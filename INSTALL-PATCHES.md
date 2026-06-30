# Patches de integração no projeto SportMoto

Trechos sugeridos para colar no projeto existente. **Adapte os
caminhos** se os arquivos estiverem em outros locais.

---

## 1. `admin/views/layouts/admin.php` — incluir CSS e JS

### Dentro do `<head>` (junto dos outros CSS):
```html
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/email-marketing.css">
```

### Antes do `</body>` (junto dos outros JS, **depois** do jQuery):
```html
<script src="<?= BASE_URL ?>/assets/js/email-marketing.js"></script>
```

> O JS detecta `.em_wrapper` na página e só executa quando estiver em
> uma tela do módulo — não impacta outras telas.

---

## 2. `admin/views/layouts/admin.php` — item de menu

Sugestão de bloco no menu lateral, dentro de um grupo "Marketing":

```html
<li class="menu-group">
    <span class="menu-title">Marketing</span>
    <ul>
        <li>
            <a href="<?= BASE_URL ?>/admin/email-marketing"
               class="<?= strpos($_SERVER['REQUEST_URI'] ?? '', '/email-marketing') !== false ? 'ativo' : '' ?>">
                <i class="fa fa-envelope"></i> Email Marketing
            </a>
        </li>
    </ul>
</li>
```

---

## 3. `admin/config/routes.php` — incluir rotas admin

Adicione **antes** de qualquer rota curinga (ex. `/{slug}`,
404-fallback):

```php
require __DIR__ . '/routes.email-marketing.php';
```

---

## 4. `config/routes.php` — incluir rotas públicas

Idem, antes da rota curinga pública:

```php
require __DIR__ . '/routes.email-marketing.php';
```

---

## 5. `config/config.php` — chave de criptografia

```php
// Gere com: openssl rand -hex 32
define('EMAIL_MARKETING_KEY', 'COLOQUE_AQUI_64_CHARS_HEX');
```

---

## 6. Cron — `/etc/cron.d/sportmoto-email`

```cron
# email worker SportMoto — roda a cada minuto
* * * * * www-data flock -n /tmp/sm-email-worker.lock /usr/local/lsws/lsphp82/bin/php /var/www/sportmoto/cli/email-worker.php >> /var/www/sportmoto/storage/logs/email-worker.log 2>&1
```

Crie o diretório de logs:
```bash
mkdir -p /var/www/sportmoto/storage/logs
chown www-data:www-data /var/www/sportmoto/storage/logs
```

---

## 7. Permissão `email_marketing` (opcional)

Se o sistema usa `AuthHelper::requirePermission()`, registre a
permissão no painel de perfis. Caso não exista, o módulo faz fallback
automático para `requireAdminLevel()` e `requireAdmin()`.
