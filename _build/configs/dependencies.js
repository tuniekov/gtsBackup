/**
 * Зависимости пакета — ставятся ПЕРЕД установкой самого пакета.
 *
 * Ключ — имя пакета (регистр как в репозитории), значение:
 *   version      — минимальная версия. Стоит такая или новее — пропускаем.
 *   service_url  — у какого провайдера искать ('modstore.pro', 'modx.com').
 *                  Не указан — берётся первый провайдер, настроенный на сайте.
 *   url          — прямая ссылка на transport.zip, если пакета нет у провайдеров
 *                  (например, релиз на GitHub). Тогда провайдер не нужен.
 *   required     — по умолчанию true: не удалось поставить — установка прерывается
 *                  с понятным сообщением. false — просто предупреждение в лог.
 *
 * Пусто (`export default {}`) — зависимостей нет.
 */
export default {
    // REST-слой и таблицы интерфейса — нужен всем gtsBackup-компонентам
    gtsAPI: {
        version: '1.1.3-beta',
        service_url: 'modstore.pro',
        required: true,
    },
    // Пример пакета из репозитория modstore:
    // pdoTools: { version: '2.10.0-pl', service_url: 'modstore.pro' },
    // Пример пакета вне провайдеров (релиз на GitHub):
    // MyExtra: { version: '1.0.0', url: 'https://github.com/user/MyExtra/releases/download/1.0.0/myextra-1.0.0-pl.transport.zip' },
}
