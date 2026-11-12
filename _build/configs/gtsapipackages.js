export default {
    gtsbackup: {
        name: 'gtsbackup',
        gtsAPITables: {
            // Токены cmd-клиента. Бессрочные, право одно — снять бекап (action.php).
            // Создаются только кнопкой «Создать токен»: сам токен показывается один раз, в базе — sha256.
            gbToken: {
                table: 'gbToken',
                class: 'gbToken',
                autocomplete_field: 'token_id',
                version: 3,
                type: 1,
                authenticated: true,
                groups: 'Administrator',
                permissions: '',
                active: true,
                properties: {
                    actions: {
                        read: {},
                        update: { groups: 'Administrator' },
                        delete: { groups: 'Administrator' },
                        create_token: {
                            action: 'gtsbackup/create_token',
                            head: true,
                            row: false,
                            icon: 'pi pi-key',
                            label: 'Создать токен',
                            groups: 'Administrator',
                            modal_form: {
                                fields: {
                                    name: { label: 'Название (чей компьютер)', type: 'text' },
                                    comment: { label: 'Комментарий', type: 'textarea' },
                                },
                                buttons: { submit: { label: 'Создать', icon: 'pi pi-check' } },
                            },
                        },
                        client_link: {
                            action: 'gtsbackup/client_link',
                            head: true,
                            row: false,
                            icon: 'pi pi-download',
                            label: 'Скачать клиент',
                            groups: 'Administrator',
                            cls: 'p-button-secondary',
                        },
                    },
                    sortby: { id: 'DESC' },
                    autocomplete: {
                        tpl: '{$name}',
                        where: { 'name:LIKE': '%query%' },
                        limit: 0,
                    },
                },
                fields: {
                    id: { type: 'view' },
                    name: { label: 'Название', type: 'text' },
                    token_hint: { label: 'Токен', type: 'view' },
                    active: { label: 'Активен', type: 'boolean' },
                    comment: { label: 'Комментарий', type: 'textarea' },
                    createdon: { label: 'Создан', type: 'view' },
                    last_backup: { label: 'Последний удачный бекап', type: 'view' },
                    last_used: { label: 'Последний запрос', type: 'view' },
                    last_ip: { label: 'IP', type: 'view' },
                    used_count: { label: 'Запросов', type: 'view' },
                },
            },
            // Журнал: задания файлов и дампы базы.
            gbLog: {
                table: 'gbLog',
                class: 'gbLog',
                autocomplete_field: '',
                version: 1,
                type: 1,
                authenticated: true,
                groups: 'Administrator',
                permissions: '',
                active: true,
                properties: {
                    actions: {
                        read: {},
                        delete: { groups: 'Administrator' },
                    },
                    sortby: { id: 'DESC' },
                    limit: 50,
                },
                fields: {
                    id: { type: 'view' },
                    createdon: { label: 'Начат', type: 'view' },
                    finishedon: { label: 'Закончен', type: 'view' },
                    token_id: { label: 'Токен', type: 'autocomplete', table: 'gbToken', readonly: 1 },
                    kind: { label: 'Что', type: 'view' },
                    mode: { label: 'Режим', type: 'view' },
                    status: { label: 'Статус', type: 'view' },
                    files: { label: 'Файлов', type: 'view' },
                    size: { label: 'Байт', type: 'view' },
                    ip: { label: 'IP', type: 'view' },
                    message: { label: 'Сообщение', type: 'view' },
                },
            },
        },
    },
}
