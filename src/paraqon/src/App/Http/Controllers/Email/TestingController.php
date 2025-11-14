<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Email;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class TestingController extends Controller
{
    public function healthCheck(Request $request)
    {
        return ['message' => 'Hello from package/paraqon email'];
    }

    public function debugViews(Request $request)
    {
        $viewFinder = app('view')->getFinder();
        $namespaces = $viewFinder->getHints();

        $result = [];

        foreach ($namespaces as $namespace => $paths) {
            $namespaceData = [
                'namespace' => $namespace,
                'paths' => []
            ];

            foreach ($paths as $path) {
                $pathData = [
                    'path' => $path,
                    'exists' => file_exists($path),
                    'files' => []
                ];

                if (file_exists($path)) {
                    $files = collect(File::allFiles($path))
                        ->map(function ($file) use ($path) {
                            return str_replace($path . '/', '', $file->getPathname());
                        })
                        ->toArray();

                    $pathData['files'] = $files;
                }

                $namespaceData['paths'][] = $pathData;
            }

            $result[] = $namespaceData;
        }

        return $result;
    }
}
