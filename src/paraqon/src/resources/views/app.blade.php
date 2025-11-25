<!DOCTYPE html>
<html lang="en">
  <body>
    @foreach ($components as $component)
    <x-dynamic namespace="paraqon" :data="$component" />
    @endforeach
  </body>
</html>
