<!DOCTYPE html>
<html lang="en">
  <head>
    <link rel="stylesheet" href="{{ asset('css/style.css') }}" />
  </head>

  <body>
    @foreach ($components as $component)
    <x-dynamic namespace="paraqon" :data="$component" />
    @endforeach
  </body>
</html>
