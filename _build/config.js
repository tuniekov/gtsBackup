export default {
    name:"gtsBackup",
    name_lower:"gtsbackup",
    version:"1.0.1",
    release:"beta",
    schema:true,
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
