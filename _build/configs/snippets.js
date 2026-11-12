export default {
    // Сниппеты ставятся ИЗ core/components/<name_lower>/elements/snippets/<file>.php.
    //
    // Механизм (НЕ через загрузку файла формой):
    //   1. PHP-код сниппета лежит в core/components/<name_lower>/elements/snippets/<file>.php
    //   2. upconfig.js копирует core/ → public/core (цикл загрузки файлов формой закомментирован — не нужен)
    //   3. установщик gtsAPI (package.class.php::snippets) читает PHP из core и создаёт modSnippet
    //
    // ВАЖНО: поле `file` — БЕЗ расширения .php (установщик добавляет '.php' сам).
    //
    // exampleSnippet:{
    //     file:'exampleSnippet',          // → core/components/<name_lower>/elements/snippets/exampleSnippet.php
    //     description:'Описание сниппета',
    //     properties:{}                   // дефолтные свойства (опц.)
    // },
}
