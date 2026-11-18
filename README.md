# HTTP Global Logger

Логирование входящих HTTP-запросов и исходящих вызовов Laravel Http. PHP 8.2+, Laravel 11–13
(конкретная версия Laravel может требовать более новый PHP).

Входящий запрос создает две записи с одинаковыми request_id и trace_id:

- http.request.started — при входе: IP, метод, путь, query, body и заголовки;
- http.request.finished — при завершении: пользователь, ответ и длительность.

Исходящий вызов Laravel Http создает одну запись http.request с запросом,
ответом или ошибкой транспорта и длительностью.
## Требования

- Laravel 11, 12 или 13; PHP 8.2+ с учетом требований выбранной версии Laravel.
- Composer и доступ к репозиторию пакета, Packagist и зависимостям.
- Рабочий Laravel logging channel с уровнем info или ниже. Для файлового канала
  процесс PHP должен иметь право записи в storage/logs.
- Для исходящих запросов используется Laravel Http facade с обычным handler stack.

База данных, миграции, отдельный сервис и очередь для работы пакета не нужны.
Тестовые зависимости устанавливаются только при разработке самого пакета.
Интеграционные тесты проверены на Laravel 13 / PHP 8.3.

## Быстрый старт

### 1. Установить пакет в Laravel-проект

Пакет пока не опубликован в Packagist. В корне приложения выполните:

```bash
composer config repositories.http-logger vcs https://github.com/sushi-market/http-global-logger
composer require sushi-market/http-logger:dev-master
```

Service provider регистрируется автоматически через package discovery. Если
приложение отключает discovery для пакета, добавьте в bootstrap/providers.php:

```php
DF\HttpLogger\HttpLoggerServiceProvider::class,
```

### 2. Опубликовать настройки и включить нужные направления

```bash
php artisan vendor:publish --tag=http-logger-config
```

Публикация создает config/http-logger.php и нужна для изменения списков
маскирования, лимитов и заголовков. С настройками по умолчанию пакет работает
и без публикации.

Добавьте в .env приложения:

```dotenv
HTTP_LOGGER_ENABLED=true
HTTP_LOGGER_INCOMING_ENABLED=true
HTTP_LOGGER_OUTGOING_ENABLED=true
```

По умолчанию incoming включен, outgoing выключен. Они независимы:
можно оставить только одно направление или включить оба.
HTTP_LOGGER_ENABLED=false отключает все логирование пакета.

### 3. Подключить middleware к API

В существующий withMiddleware в bootstrap/app.php добавьте:

```php
use DF\HttpLogger\Middleware\LogHttpRequest;
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->api(prepend: [LogHttpRequest::class]);
})
```

Логируются маршруты группы api, обычно из routes/api.php. Группа web не затрагивается.
Проверьте, что API-маршруты приложения действительно используют эту группу:
сам по себе префикс /api middleware не подключает. Не заменяйте остальную настройку
withMiddleware — добавьте строку в существующий callback.

Исходящее логирование подключается глобально при включенном outgoing:
ничего добавлять к отдельным Http::get()/post(), jobs и командам не требуется.
Если нужно только outgoing, middleware можно не подключать. Чтобы при этом
получать trace_id из входящего запроса, оставьте middleware и выключите incoming.

### 4. Выбрать канал логов

Без дополнительных настроек используется стандартный канал приложения из
config/logging.php (обычно выбран через LOG_CHANNEL). Формат определяет formatter
этого канала. Если он отправляет логи в stderr, ищите записи в логах контейнера.

Для отдельного файла добавьте в существующий channels в config/logging.php:

```php
'http' => [
    'driver' => 'daily',
    'path' => storage_path('logs/http.log'),
    'level' => 'info',
    'days' => 14,
],
```

И укажите в .env:

```dotenv
HTTP_LOGGER_CHANNEL=http
```

Записи появятся в storage/logs/http-YYYY-MM-DD.log. Можно использовать любой
уже настроенный канал вместо http. Канал должен существовать в приложении.
Для разных каналов у направлений задайте HTTP_LOGGER_INCOMING_CHANNEL и
HTTP_LOGGER_OUTGOING_CHANNEL; иначе оба наследуют общий канал.

### 5. Применить настройки и проверить

```bash
php artisan config:clear
```

Если при развертывании используется кеш конфигурации, пересоберите его:

```bash
php artisan config:cache
```

Перезапустите долгоживущие процессы, которые используют пакет:
queue workers через php artisan queue:restart, Octane через php artisan octane:reload,
Horizon через принятый в проекте процесс перезапуска.

Вызовите существующий API-маршрут. При входе появится http.request.started, после отправки ответа —
http.request.finished; обе записи имеют direction=incoming. Вызовите внешний сервис через Http из приложения:
появится direction=outgoing. Если вызов сделан внутри этого API-запроса, trace_id
у двух записей совпадет, request_id будет разным.

Пример проверки идентификаторов (подставьте адрес своего маршрута):

```bash
curl -H "X-Trace-Id: demo-trace-001" -H "X-Request-Id: demo-request-001" https://your-app.example/api/your-route
```

В записи incoming ожидаются trace_id=demo-trace-001 и request_id=demo-request-001.
У исходящих вызовов этого маршрута ожидается тот же trace_id и собственный request_id.
Если маршрут не выполняет Http-вызовов, записи outgoing не будет.

### Если записей нет

- Убедитесь, что нужная версия пакета установлена и service provider зарегистрирован.
- Проверьте переключатели, config cache и перезапуск workers/Octane.
- Для incoming проверьте группу api и except; для outgoing — использование Laravel Http.
- Проверьте существование канала, его минимальный уровень и доступность хранилища.
- Ищите http.logger.failed в стандартном логе приложения.
- Входящая finished-запись появляется в terminate, после отправки ответа. При аварийном
  завершении процесса запись не гарантируется.

## Другие способы подключения входящих запросов

Для отдельного маршрута используйте middleware(LogHttpRequest::class).
Для всего приложения используйте $middleware->prepend(LogHttpRequest::class)
вместо добавления в api; это затронет web-маршруты и админку.
Групповое подключение не охватывает запросы, которые не дошли до этой группы,
в том числе некоторые 404 и ошибки до маршрутизации.
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

Запись http.request.started выполняется до вызова следующего middleware.
Запись http.request.finished выполняется в terminate после отправки ответа.
duration_ms включает отправку ответа и время до вызова terminate, а не только
работу контроллера. Итоговые ошибки, преобразованные Laravel в Response,
также логируются. При аварийном завершении процесса запись не гарантируется.
При ручном использовании HTTP kernel необходимо вызывать kernel->terminate().

Состояние хранится в Request, поэтому singleton middleware не требуется.
Повторный terminate не создает повторную finished-запись. Ошибка логирования перехватывается,
в стандартный Laravel-лог записываются этап сбоя и класс исключения. IP зависит от настроек
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
cd /path/to/http-global-logger
composer install
composer test
```

Отдельный Laravel-проект для тестов не требуется: Orchestra Testbench
создает тестовое приложение. Composer должен иметь доступ к Packagist и GitHub.
Используйте PHP и Composer одной среды — для проекта в WSL запускайте их
внутри WSL, чтобы избежать проблем Windows с UNC-путями.

## Входящие и исходящие запросы

Направления включаются независимо в config/http-logger.php:

```php
'incoming' => ['enabled' => true, 'channel' => null],
'outgoing' => ['enabled' => false, 'channel' => null],
'trace_header' => 'X-Trace-Id',
'request_header' => 'X-Request-Id',
```

Или через .env:

```dotenv
HTTP_LOGGER_INCOMING_ENABLED=true
HTTP_LOGGER_OUTGOING_ENABLED=true
HTTP_LOGGER_INCOMING_CHANNEL=http
HTTP_LOGGER_OUTGOING_CHANNEL=http
```

Пустой channel направления наследует общий HTTP_LOGGER_CHANNEL, затем стандартный
канал приложения. HTTP_LOGGER_ENABLED=false отключает оба направления.
Исходящие выключены по умолчанию. После изменения опубликованной конфигурации
добавьте новые ключи и обновите config cache приложения, если он используется.

Входящие требуют подключения LogHttpRequest middleware. Исходящие автоматически
подключаются к Laravel Http через глобальный Guzzle middleware, включая jobs,
команды и Http::pool(). Прямые Guzzle/cURL и пользовательский setClient(),
обходящий стандартный handler stack Laravel, не охватываются.

Каждая запись содержит direction (incoming/outgoing), request_id и trace_id.
Входящий X-Request-Id сохраняется, если корректен, иначе создается UUID.
У каждого исходящего вызова, включая retry, новый X-Request-Id.
trace_id берется из входящего X-Trace-Id либо создается; передается во внешние
вызовы. Допустимы буквы, цифры, точка, дефис, подчеркивание, до 128 символов.
Это пользовательский correlation header, не реализация W3C traceparent.

Если incoming выключен, оставьте middleware для установления trace-контекста
исходящих вызовов из входящего запроса. Если он не подключен, исходящие используют
trace текущего scope. В CLI/queue используется scoped-контекст Laravel:
стандартный worker очищает его между jobs. Для явной операции можно задать trace:

```php
app(\DF\HttpLogger\TraceContext::class)->run($traceId, function () {
    // Http::get(...), другие вызовы этой операции.
});
```

Middleware восстанавливает предыдущий trace через finally, в том числе при
исключении. В долгоживущих пользовательских циклах задавайте контекст через run()
для каждой операции. Передачу trace в payload очереди выполняет приложение.
Явный корректный X-Trace-Id исходящего запроса имеет приоритет над контекстом.
Входящие заголовки и response headers пакетом не изменяются; исходящие получают
trace/request headers только при включенном outgoing.

Исходящая запись содержит method, URL без query/userinfo/fragment, отдельно
маскированный query, заголовки, JSON/form body, статус/заголовки/JSON body ответа,
duration_ms и error. При ошибке соединения response=null, error.type содержит
класс ошибки. Сообщения исключений не сохраняются, чтобы не раскрывать секреты.
Каждая попытка retry — отдельная запись; duration_ms относится к одной попытке,
без задержек между повторами. Ответы 4xx/5xx записываются как обычные ответы;
throw/retry продолжает определять приложение.

Пакет не меняет обработку исключений приложения/HTTP-клиента, не вызывает throw()
и не выполняет retries. Ошибки самого логирования записываются в стандартный Laravel-канал и подавляются. Оригинальные
response и причины отклонения promise передаются дальше.

PSR-7 body читается только при isSeekable() и isReadable(), с лимитом и
восстановлением позиции курсора в finally. Неперематываемые потоки не читаются.
При options stream=true или sink тело ответа не читается даже при возможности
перемотки. JSON/form request body маскируется; multipart/файлы и неструктурированные
данные опускаются. Пакет не закрывает и не заменяет потоки.

Набор интеграционных тестов проверяет независимые переключатели, сохранение
исключений и ответов, сбой logger, trace-контекст, retry, pool и позиции потоков.

### Внутренние ошибки пакета

При сбое захвата данных или записи HTTP-лога пакет пишет ERROR http.logger.failed
в стандартный канал приложения (независимо от каналов incoming/outgoing).
Контекст содержит stage и exception_type. Сообщение исключения, стек и body
в диагностическую запись не включаются, чтобы не раскрыть секреты.

Работа запроса продолжается. Если недоступен также стандартный logger, ошибка
диагностики подавляется; повторное рекурсивное логирование блокируется.
Исключения бизнес-кода обрабатывает Laravel и само приложение: пакет не подавляет
их и не пытается продолжить выполнение после необработанного исключения.

## Начало и завершение входящего запроса

Связывайте http.request.started и http.request.finished по request_id; trace_id
объединяет весь путь через сервисы. Поле event равно started или finished.
Данные запроса записываются только в started, ответ и duration_ms — в finished.
finished также содержит started_at, finished_at и пользователя после аутентификации.

В started user_id/user_type равны null: при раннем подключении middleware
аутентификация еще не выполнена. Пакет не запускает ее принудительно.

Если приложение выбросило исключение после started, эта запись уже передана
logger. Обычное исключение Laravel преобразует в ответ, например 500, после чего
пакет записывает finished. Если terminate не был вызван, останется только started.
Отсутствие finished может означать обрыв процесса, незавершенный запрос или сбой
логирования; само по себе оно не доказывает падение приложения.

При аварии до входа в middleware или при недоступном logger даже started может
отсутствовать. Фактическая сохранность записи зависит от выбранного log handler.
