Plugin for fast online buying tickets

Архитектура плагина

qr-tickets/qr-tickets.php:17 собирает все классы из includes, определяет константы и на активации регистрирует CPT, переписывает правила, cron и endpoint “My tickets”.
qr-tickets/qr-tickets.php:33 грузит QRTickets_Loader, который инициализирует нужные модули только там, где они действительно есть (админка, фронт, cron, DirectPay).
qr-tickets/includes/class-qr-tickets-loader.php:7 инстанцирует основные сервисы плагина и решает, когда их регистрировать (например, админские хуки — только в is_admin()).
Основные сервисы

qr-tickets/includes/class-qr-tickets-directpay.php:7 перехватывает пути вида /buy/30m, создаёт заказ WooCommerce, проставляет метаданные (email, locale, QR устройства), отправляет на Barion и логгирует результат.
qr-tickets/includes/class-qr-tickets-issuer.php:7 вешается на завершение оплаты, выписывает одиночный CPT “ticket”, подбирает email из разных источников, синхронизирует билет с DPMK, прикладывает QR (API либо fallback через GD) и ставит редирект на билет.
qr-tickets/includes/class-qr-tickets-dpmk-service.php:7 управляет интеграцией с DPMK: проверяет конфигурацию/токен, мапит типы на ID, пробует выкуп и сохраняет статус/ошибки в метаданные.
qr-tickets/includes/class-qr-tickets-dpmk-client.php:7 кеширует OAuth-токен, ретраит запросы (до 3 раз, повторная авторизация на 401), возвращает структуру ok/err.
qr-tickets/includes/class-qr-tickets-cron.php:7 добавляет собственный “minutely” график, помечает просроченные билеты и периодически повторяет неудачные синхронизации (с паузой минимум 5 минут).
qr-tickets/includes/class-qr-tickets-account.php:7 выводит список билетов в “My Account” Woo с фильтрацией по автору/почте и быстрым доступом к просмотру.
qr-tickets/includes/class-qr-tickets-admin.php:7 рисует страницу настроек в Settings → QR Tickets, валидирует поля Woo товаров, URL заглушки, креды DPMK и чекбоксы test-mode/“delayless with device”.
Отображение и фронтенд

qr-tickets/includes/class-qr-tickets-template.php:7 подменяет single-шаблон билета, подключает CSS/JS, локализует ajax nonce и отвечает за рассылку билета на email по AJAX (две отправки максимум).
qr-tickets/templates/single-ticket.php:12 выводит QR, статус, счётчик времени (обновляет статус на “expired” при открытии), ссылку на копирование кода и форму отправки на e-mail.
qr-tickets/assets/js/qr-ticket.js:2 реализует обратный отсчёт с автообновлением страницы, копирование кода в буфер и AJAX-отправку билета.