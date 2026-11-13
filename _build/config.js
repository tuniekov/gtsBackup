export default {
    name:"gtsBackup",
    name_lower:"gtsbackup",
    version:"1.1.5",
    release:"beta",
    schema:true,
    // рабочая папка на сайте сборки (архивы, манифесты) — не в пакет
    exclude:["core/tmp"],
    assets:true,
    core:true,
    update:{
        snippets: true,
        plugins: true,
        chunks: true,
        templates: true,
        // настройки ставятся, если их нет; значения на сайте не перетираются
        settings: false,
        menus: true,
        gtsapipackages: true,
    }
}
