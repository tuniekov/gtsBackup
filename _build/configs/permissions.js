/**
 * Разрешения MODX, которые привозит пакет: шаблон разрешений + сами разрешения
 * + (необязательно) готовые политики доступа.
 *
 * Зачем это, а не своя группа пользователей: админ чужого сайта лишним группам не
 * обрадуется, а группы у всех свои. Пакет привозит ШАБЛОН, админ вешает политику на
 * ТУ группу, которая у него уже есть.
 *
 * Проверяются штатно — в gtsapipackages.js у таблицы указывается `permissions`
 * (рядом с `groups`), gtsAPI зовёт modX::hasPermission.
 *
 * Резолвер идемпотентен; при удалении пакета ничего не сносится — политики уже
 * могут висеть на группах пользователей.
 *
 * Пример:
 *
 * export default {
 *     'MyPackageTemplate': {
 *         description: 'Права компонента MyPackage',
 *         template_group: 'Admin',              // имя или id группы шаблонов (по умолчанию Admin)
 *         permissions: {
 *             'mypackage_view': 'Просмотр раздела',
 *             'mypackage_edit': 'Редактирование',
 *         },
 *         policies: {                            // необязательно: готовые политики
 *             'MyPackage':       { description: 'Просмотр',     permissions: ['mypackage_view'] },
 *             'MyPackage Admin': { description: 'Полный доступ', permissions: ['mypackage_view', 'mypackage_edit'] },
 *         },
 *     },
 * }
 */
export default {}
