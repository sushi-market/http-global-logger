# HTTP Global Logger

Laravel middleware для входящих HTTP-запросов. PHP 8.2+, Laravel 11–13
(конкретная версия Laravel может требовать более новый PHP).

Один запрос — одна запись `http.request` в стандартном Laravel logging channel:
- инициатор: IP, ID и класс авторизованного пользователя;
- метод, путь, query parameters, JSON/form body и заголовки запроса;
- статус, заголовки и JSON body ответа;
- время начала UTC и длительность в миллисекундах.

## Установка

Пакет пока не опубликован в Packagist. В composer.json приложения добавьте:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/sushi-market/http-global-logger"}
  ]
}
```

После публикации кода в ветку master:

```bash
composer require sushi-market/http-logger:dev-master
php artisan vendor:publish --tag=http-logger-config
```

Service provider подключается через package discovery.

## Подключение middleware

Для API-маршрутов добавьте в существующий withMiddleware в bootstrap/app.php:

```php
use DF\HttpLogger\Middleware\LogHttpRequest;
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->api(prepend: [LogHttpRequest::class]);
})
```

Либо подключите к отдельным маршрутам через middleware(LogHttpRequest::class).
Для охвата 404 и ошибок до маршрутизации используйте глобальное подключение.

## Настройки

config/http-logger.php:
- enabled / HTTP_LOGGER_ENABLED — включение;
- channel / HTTP_LOGGER_CHANNEL — канал из config/logging.php (null — стандартный);
- level — уровень записи;
- except — шаблоны путей, например health, telescope/*;
- max_body_bytes — лимит каждого body/query/набора заголовков;
- redacted_fields, redacted_headers — имена скрываемых полей, без учета регистра.

Пароли, токены, cookies и Authorization скрываются по умолчанию. Поля
маскируются рекурсивно по точному имени ключа. Добавляйте в конфигурацию
чувствительные поля вашего приложения. Путь сохраняется без query string.

JSON и form body сохраняются как структуры. Необработанный текст, HTML,
JSON-скаляры, бинарные данные и содержимое загруженных файлов не записываются:
вместо них сохраняется причина omitted. Это предотвращает утечку секретов,
которые нельзя надежно скрыть по имени поля. Потоки и скачиваемые файлы
не читаются. Слишком большие данные опускаются целиком, без обрезанного JSON.

Запись выполняется в terminate после формирования и отправки ответа.
duration_ms включает отправку ответа и время до вызова terminate, а не только
работу контроллера. Итоговые ошибки, преобразованные Laravel в Response,
также логируются. При аварийном завершении процесса запись не гарантируется.
При ручном использовании HTTP kernel необходимо вызывать kernel->terminate().

Состояние хранится в Request, поэтому singleton middleware не требуется.
Повторный terminate не создает вторую запись. Ошибка логирования перехватывается,
в PHP error_log попадает только класс исключения. IP зависит от настроек
trusted proxies приложения; user_id определяется после выполнения middleware
аутентификации. Middleware не добавляет себя в приложение автоматически.

## Проверка

```bash
composer install
composer test
```


### Запуск в WSL

В терминале Ubuntu из каталога пакета:

```bash
cd /home/nikita/Projects/http-global-logger
composer install
composer test
```

Отдельный Laravel-проект для тестов не требуется: Orchestra Testbench
создает тестовое приложение. Composer должен иметь доступ к Packagist и GitHub.
Используйте PHP и Composer одной среды — для проекта в WSL запускайте их
внутри WSL, чтобы избежать проблем Windows с UNC-путями.
