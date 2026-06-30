<?php
/**
 * Carrega o arquivo .env da raiz do projeto.
 * Simples, seguro, sem dependências externas.
 */
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return;
    }

    $linhas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($linhas as $linha) {
        $linha = trim($linha);

        // Ignora comentários e linhas vazias
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }

        // Precisa ter o sinal de =
        if (!str_contains($linha, '=')) {
            continue;
        }

        [$chave, $valor] = explode('=', $linha, 2);

        $chave = trim($chave);
        $valor = trim($valor);

        // Remove aspas do valor (simples ou duplas)
        if (
            (str_starts_with($valor, '"') && str_ends_with($valor, '"')) ||
            (str_starts_with($valor, "'") && str_ends_with($valor, "'"))
        ) {
            $valor = substr($valor, 1, -1);
        }

        // Ignora se a variável já estiver definida no ambiente real
        if (!isset($_ENV[$chave]) && !isset($_SERVER[$chave])) {
            $_ENV[$chave]    = $valor;
            $_SERVER[$chave] = $valor;
            putenv("{$chave}={$valor}");
        }
    }
}