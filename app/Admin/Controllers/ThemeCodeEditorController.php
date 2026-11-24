<?php

namespace App\Admin\Controllers;

use App\Admin\Navigation\AdminNavigation;
use App\Storefront\Infrastructure\ThemeCodeEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ThemeCodeEditorController
{
    public function index(Request $request, ThemeCodeEditor $editor): Response
    {
        Gate::authorize('theme-code.manage');

        $problem = null;
        try {
            $files = $editor->files();
        } catch (HttpException $failure) {
            $files = [];
            $problem = $failure->getMessage();
        } catch (\Throwable $failure) {
            report($failure);
            $files = [];
            $problem = __('code_editor.unavailable');
        }

        return Inertia::render('Admin/CodeEditor/Index', [
            'navigation' => app(AdminNavigation::class)->forUser($request->user()),
            'breadcrumbs' => [['label' => __('navigation.dashboard'), 'href' => '/admin'], ['label' => __('code_editor.title'), 'href' => '/admin/code-editor']],
            'files' => $files, 'problem' => $problem,
        ]);
    }

    public function read(Request $request, ThemeCodeEditor $editor): JsonResponse
    {
        Gate::authorize('theme-code.manage');
        $data = $request->validate(['path' => ['required', 'string', 'max:180']]);

        return $this->fileResponse(fn () => $editor->read($data['path']));
    }

    public function save(Request $request, ThemeCodeEditor $editor): JsonResponse
    {
        Gate::authorize('theme-code.manage');
        $data = $request->validate(['path' => ['required', 'string', 'max:180'], 'content' => ['present', 'nullable', 'string', 'max:262144'], 'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->fileResponse(fn () => $editor->save($data['path'], $data['content'] ?? '', $data['version']));
    }

    /** @param callable(): array<string,mixed> $operation */
    private function fileResponse(callable $operation): JsonResponse
    {
        try {
            return response()->json($operation())->header('Cache-Control', 'no-store');
        } catch (ValidationException $failure) {
            return response()->json(['errors' => $failure->errors()], 422);
        } catch (HttpException $failure) {
            return response()->json(['message' => $failure->getMessage()], $failure->getStatusCode());
        } catch (\Throwable $failure) {
            report($failure);

            return response()->json(['message' => __('code_editor.write_failed')], 500);
        }
    }
}
