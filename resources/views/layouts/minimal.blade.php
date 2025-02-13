<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="i18n-mdate" content='{!! json_encode(ProcessMaker\i18nHelper::mdates()) !!}'>
    <title>@yield('title',__('Welcome')) - ProcessMaker</title>
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon.png">
@yield('css')
</head>
<body>
    <div class="container" id="app">
@yield('content')
    </div>
@yield('js')
</body>
</html>
