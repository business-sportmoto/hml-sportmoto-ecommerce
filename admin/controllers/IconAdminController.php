<?php

class IconAdminController extends Controller
{
    public function store(): void
    {
        $this->verifyCsrf();

        $key   = trim($_POST['key'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $tags  = trim($_POST['tags'] ?? '');
        $svg   = trim($_POST['svg'] ?? '');

        if ($key === '' || $svg === '') {
            $this->json([
                'ok' => false,
                'msg' => 'Informe a key e o código SVG.'
            ], 422);
        }

        if (!preg_match('/^[a-z0-9\-_]+$/', $key)) {
            $this->json([
                'ok' => false,
                'msg' => 'A key deve conter apenas letras minúsculas, números, hífen ou underline.'
            ], 422);
        }

        if (stripos($svg, '<svg') === false || stripos($svg, '</svg>') === false) {
            $this->json([
                'ok' => false,
                'msg' => 'Cole um SVG completo válido.'
            ], 422);
        }

        // Segurança básica: remove scripts/eventos perigosos
        $svg = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $svg);
        $svg = preg_replace('/\son[a-z]+\s*=\s*("|\').*?\1/is', '', $svg);
        $svg = preg_replace('/javascript:/i', '', $svg);

        $file = ROOT_PATH . '/assets/icons.json';

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $icons = [];

        if (file_exists($file)) {
            $json = file_get_contents($file);
            $icons = json_decode($json, true);
            if (!is_array($icons)) {
                $icons = [];
            }
        }

        foreach ($icons as $icon) {
            if ($icon['key'] === $key) {
                $this->json([
                    'ok' => false,
                    'msg' => 'Já existe um ícone com essa key.'
                ], 409);
            }
        }

        $icon = [
            'key' => $key,
            'label' => $label ?: $key,
            'tags' => array_values(array_filter(array_map('trim', explode(',', $tags)))),
            'svg' => $svg
        ];

        $icons[] = $icon;

        file_put_contents(
            $file,
            json_encode($icons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        $this->json([
            'ok' => true,
            'msg' => 'Ícone salvo com sucesso.',
            'icon' => $icon
        ]);
    }

    public function update(): void
    {
        $this->verifyCsrf();

        $key   = $_POST['key'];
        $svg   = $_POST['svg'];
        $label = $_POST['label'];
        $tags  = $_POST['tags'];

        $file = ROOT_PATH . '/assets/icons.json';
        $icons = json_decode(file_get_contents($file), true);

        foreach ($icons as &$icon) {

            if ($icon['key'] === $key) {

                $icon['svg']   = $svg;
                $icon['label'] = $label;
                $icon['tags']  = array_map('trim', explode(',', $tags));

                break;
            }
        }

        file_put_contents($file, json_encode($icons, JSON_PRETTY_PRINT));

        $icon = [
            'key' => $key,
            'label' => $label ?: $key,
            'tags' => array_values(array_filter(array_map('trim', explode(',', $tags)))),
            'svg' => $svg
        ];

        $this->json([
            'ok' => true,
            'msg' => 'Ícone atualizado com sucesso.',
            'icon' => $icon
        ]);
    }

    public function delete(): void
    {
        $key = $_POST['key'];

        $file = ROOT_PATH . '/storage/icons/icons.json';
        $icons = json_decode(file_get_contents($file), true);

        $icons = array_values(array_filter($icons, function ($icon) use ($key) {
            return $icon['key'] !== $key;
        }));

        file_put_contents($file, json_encode($icons, JSON_PRETTY_PRINT));

        $this->json(['ok' => true]);
    }
}