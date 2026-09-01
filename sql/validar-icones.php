<?php
/**
 * Valida todas as chaves de icone do projeto contra assets/icons.json.
 *
 * Cobre as tres fontes: string literal no PHP, referencia no sprite/JS e valor
 * gravado em coluna de banco. Foi a terceira que escapou na primeira varredura
 * — a chave vinha do banco, nao do codigo.
 *
 * Uso:  php sql/validar-icones.php
 */
chdir(dirname(__DIR__));
require 'config/defines.php'; require 'config/config.php'; require 'config/database.php';

$lib = array_column(json_decode(file_get_contents('assets/icons.json'), true), 'key');
$falta = [];
$vistas = 0;

$anotar = function (string $k, string $onde) use ($lib, &$falta, &$vistas) {
    $k = trim($k);
    if ($k === '' || str_contains($k, '$')) return;   // chave dinamica: nao da para validar aqui
    $vistas++;
    if (!in_array($k, $lib, true)) $falta[$k][] = $onde;
};

// 1) PHP: IconLibrary::render/ref e helpers $ico()
$rx = '/(?:IconLibrary::(?:render|ref)|\$ico)\(\s*[\'"]([^\'"]+)[\'"]/';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace(DIRECTORY_SEPARATOR, '/', $f->getPathname());
    if (!preg_match('#^\./(app|admin|views)/#', $p) || !str_ends_with($p, '.php')) continue;
    if (preg_match_all($rx, (string)file_get_contents($p), $m)) {
        foreach ($m[1] as $k) $anotar($k, ltrim($p, './'));
    }
}

// 2) JS: <use href="#i-chave"> e LOG.ico('chave')
foreach (glob('admin/assets/js/*.js') as $p) {
    // Comentario fora: um exemplo de uso dentro de /* */ nao e referencia real.
    $t = (string)preg_replace(['#/\*.*?\*/#s', '#//[^
]*#'], '', (string)file_get_contents($p));
    if (preg_match_all('/href="#i-([a-z0-9_-]+)"/', $t, $m)) foreach ($m[1] as $k) $anotar($k, $p);
    if (preg_match_all('/LOG\.ico\(\s*[\'"]([^\'"]+)[\'"]/', $t, $m)) foreach ($m[1] as $k) $anotar($k, $p);
}

// 3) Banco: qualquer coluna chamada icone / icone_key
$pdo = Database::getInstance()->getConnection();
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) {
        if (!preg_match('/^(icone|icon|icone_key|icon_key)$/i', $c['Field'])) continue;
        $col = $c['Field'];
        foreach ($pdo->query("SELECT DISTINCT `$col` v FROM `$t` WHERE `$col` IS NOT NULL AND `$col` <> ''") as $r) {
            $anotar((string)$r['v'], "banco: $t.$col");
        }
    }
}

// 4) Cobertura do sprite.
//    Uma chave pode existir no acervo e mesmo assim nao aparecer na tela: se o
//    <symbol> nao estiver no sprite impresso na pagina, o <use> aponta para o
//    vazio. Foi assim que o checkout de expedicao ficou sem icone nenhum — nada
//    quebra, nada vai para log, o icone simplesmente some.
$spritePhp = (string)file_get_contents('admin/views/partials/_sprite.php');
preg_match_all("/'([a-z0-9_-]+)'/", $spritePhp, $ms);
$noSprite = $ms[1];

$foraDoSprite = [];
$viaSprite = function (string $k, string $onde) use ($noSprite, &$foraDoSprite) {
    $k = trim($k);
    if ($k === '' || str_contains($k, '$') || in_array($k, $noSprite, true)) return;
    $foraDoSprite[$k][] = $onde;
};

$itv = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('admin/views', FilesystemIterator::SKIP_DOTS));
foreach ($itv as $f) {
    $p = str_replace(DIRECTORY_SEPARATOR, '/', $f->getPathname());
    if (!str_ends_with($p, '.php') || str_ends_with($p, '_sprite.php')) continue;
    $t = (string)file_get_contents($p);
    if (preg_match_all('/(?:\$ico|IconLibrary::ref)\(\s*[\'"]([^\'"]+)[\'"]/', $t, $m)) {
        foreach ($m[1] as $k) $viaSprite($k, $p);
    }
}
foreach (glob('admin/assets/js/*.js') as $p) {
    $t = (string)preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string)file_get_contents($p));
    if (preg_match_all('/href="#i-([a-z0-9_-]+)"/', $t, $m))        foreach ($m[1] as $k) $viaSprite($k, $p);
    if (preg_match_all('/LOG\.ico\(\s*[\'"]([^\'"]+)[\'"]/', $t, $m)) foreach ($m[1] as $k) $viaSprite($k, $p);
}
printf("acervo   : %d icones\n", count($lib));
printf("checadas : %d referencias\n", $vistas);
printf("quebradas: %d\n\n", count($falta));
foreach ($falta as $k => $onde) {
    printf("  %-24s <- %s\n", $k, implode(', ', array_unique($onde)));
}
if ($foraDoSprite) {
    printf("
FORA DO SPRITE (existem no acervo, mas nao renderizam):
");
    foreach ($foraDoSprite as $k => $onde) {
        printf("  %-24s <- %s
", $k, implode(', ', array_unique($onde)));
    }
    printf("  -> acrescente em admin/views/partials/_sprite.php
");
}

exit((count($falta) || count($foraDoSprite)) ? 1 : 0);
