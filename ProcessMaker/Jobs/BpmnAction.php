<?php

namespace ProcessMaker\Jobs;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use ProcessMaker\BpmnEngine;
use ProcessMaker\Models\Process as Definitions;
use ProcessMaker\Models\ProcessRequest;
use ProcessMaker\Models\ProcessRequestLock;
use Throwable;

abstract class BpmnAction implements ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    /**
     * @var BpmnEngine
     */
    protected $engine;

    /**
     * @var ProcessRequest
     */
    protected $instance;

    protected $tokenId = null;

    protected $disableGlobalEvents = false;

    /**
     * @var ProcessRequestLock
     */
    private $lock;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $response = null;
        try {
            extract($this->loadContext());
            $this->engine = $engine;
            $this->instance = $instance;

            //Do the action
            $response = App::call([$this, 'action'], compact('definitions', 'instance', 'token', 'process', 'element', 'data', 'processModel'));

            //Run engine to the next state
            $this->engine->runToNextState();
        } catch (Throwable $exception) {
            Log::error($exception->getMessage());
            // Change the Request to error status
            $request = !$this->instance && $this instanceof StartEvent ? $response : $this->instance;
            if ($request) {
                $request->logError($exception, $element);
            }
        } finally {
            $this->unlock();
        }

        return $response;
    }

    /**
     * Load the context for the action
     *
     * @return array
     */
    private function loadContext()
    {
        //Load the process definition
        if (isset($this->instanceId)) {
            $instance = $this->lockInstance($this->instanceId);
            if (!$instance) {
                throw new Exception('Unable to lock instance ' . $this->instanceId);
            }
            $processModel = $instance->process;
            $definitions = ($instance->processVersion ?? $instance->process)->getDefinitions(true);
            $engine = app(BpmnEngine::class, ['definitions' => $definitions, 'globalEvents' => !$this->disableGlobalEvents]);
            $instance = $engine->loadProcessRequest($instance);
        } else {
            $processModel = Definitions::find($this->definitionsId);
            $definitions = $processModel->getDefinitions();
            $engine = app(BpmnEngine::class, ['definitions' => $definitions, 'globalEvents' => !$this->disableGlobalEvents]);
            $instance = null;
        }

        //Load the instances of the process and its collaborators
        if ($instance && $instance->collaboration) {
            foreach ($instance->collaboration->requests as $request) {
                if ($request->getKey() !== $instance->getKey()) {
                    $engine->loadProcessRequest($request);
                }
            }
        }

        //Get the BPMN process instance
        $process = null;
        if (isset($this->processId)) {
            $process = $definitions->getProcess($this->processId);
        }

        //Load token and element
        $token = null;
        $element = null;
        if ($instance && isset($this->tokenId)) {
            foreach ($instance->getTokens() as $token) {
                if ($token->getId() === $this->tokenId) {
                    $element = $definitions->getElementInstanceById($token->getProperty('element_ref'));
                    break;
                } else {
                    $token = null;
                }
            }
        } elseif (isset($this->elementId)) {
            $element = $definitions->getElementInstanceById($this->elementId);
        }

        //Load data
        $data = isset($this->data) ? $this->data : null;

        return compact('definitions', 'instance', 'token', 'process', 'element', 'data', 'processModel', 'engine');
    }

    /**
     * This method execute a callback with the context updated
     *
     * @return array
     */
    public function withUpdatedContext(callable $callable)
    {
        $context = $this->loadContext();
        return App::call($callable, $context);
    }

    /**
     * Lock the instance and its collaborators
     *
     * @param int $instanceId
     *
     * @return ProcessRequest
     */
    protected function lockInstance($instanceId)
    {
        try {
            $instance = ProcessRequest::findOrFail($instanceId);
            if (config('queue.default') === 'sync') {
                return $instance;
            }
            if ($instance->collaboration) {
                $ids = $instance->collaboration->requests->pluck('id')->toArray();
            } else {
                $ids = [$instance->id];
            }
            $lock = $this->requestLock($ids);
            // If the processes are going to have thousands of parallel instances,
            // the lock will be released after a while.
            $timeout = config('app.bpmn_actions_max_lock_timeout', 6000) ?: 6000;
            $interval = config('app.bpmn_actions_lock_check_interval', 1000) ?: 1000;
            $maxRetries = ceil($timeout / $interval * 1000);
            for ($tries=0; $tries < $maxRetries; $tries++) {
                $currentLock = $this->currentLock($ids);
                if (!$currentLock) {
                    if (ProcessRequest::find($instanceId)) {
                        $lock = $this->requestLock($ids);
                    } else {
                        return false;
                    }
                } elseif ($lock->id == $currentLock->id) {
                    $instance = ProcessRequest::findOrFail($instanceId);
                    $this->activateLock($lock);
                    return $instance;
                }
                // average of lock time is 1 second
                usleep($interval);
            }
        } catch (Throwable $exception) {
            Log::error($exception->getMessage());
            return false;
        }
        return false;
    }

    /**
     * Request a lock for the instance
     * @param array $ids
     * @return ProcessRequestLock
     */
    protected function requestLock($ids)
    {
        return ProcessRequestLock::create([
            'request_id' => $this->instanceId,
            'token_id' => $this->tokenId,
            'request_ids' => $ids,
        ]);
    }

    /**
     * Get the current lock
     * @param array $ids
     * @return ProcessRequestLock|null
     */
    protected function currentLock($ids)
    {
        $query = ProcessRequestLock::whereNotDue()
            ->orderBy('id', 'asc')
            ->limit(1);
        $query->where(function ($query) use ($ids) {
            foreach ($ids as $id) {
                $query->orWhereJsonContains('request_ids', $id);
            }
        });
        return $query->first();
    }

    /**
     * Activate the lock
     * @param ProcessRequestLock $lock
     * @return void
     */
    protected function activateLock(ProcessRequestLock $lock)
    {
        $lock->activate();
        $this->lock = $lock;
        // Remove due locks
        ProcessRequestLock::where('due_at', '<', Carbon::now())->delete();
    }

    /**
     * Unlock the instance and its collaborators
     */
    protected function unlock()
    {
        if (isset($this->lock)) {
            $this->lock->delete();
        }
    }

    /**
     * Lock the instance and its collaborators
     *
     * @param int $instanceId
     *
     * @return ProcessRequest
     */
    protected function unlockInstance($instanceId)
    {
        $instance = ProcessRequest::find($instanceId);
        $instance->unlock();
        return $instance;
    }
}
