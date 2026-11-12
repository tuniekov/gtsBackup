export default {
    // Страница gtsAPI: что показывать — JSON в настройке gtsbackup_admin (settings.js).
    'Бекапы': {
        description: 'Токены cmd-клиента и журнал бекапов',
        action: 'home',
        namespace: 'gtsapi',
        params: '&config=gtsbackup_admin',
        parent: 'components',
        menuindex: 0,
    },
}
