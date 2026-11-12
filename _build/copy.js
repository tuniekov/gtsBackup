import fs from 'fs';
import config from "./config.js";
import prompt from 'prompt';
import replace from 'replace-in-file'

// Имя пакета можно передать аргументом командной строки:
//   node _build/copy.js MyPackage
// Если не передано — спросим интерактивно через prompt.
const argName = process.argv[2];

async function run(packagename) {
    console.log('Копируем пакет в папку ../' + packagename);
    fs.cpSync(".", "../" + packagename, {
        recursive: true,
        filter: (src) => {
            if (src.indexOf("\\node_modules") != -1) return false
            if (src.indexOf("\\.gitignore") != -1) return true
            if (src.indexOf("\\.git") != -1) return false
            if (src.indexOf("node_modules") != -1) return false
            if (src.indexOf(".gitignore") != -1) return true
            if (src.indexOf(".git") != -1) return false
            return true
        },
    });

    const options = {
        files: [
            '../' + packagename + '/_build/configs/*',
            '../' + packagename + '/_build/config.js',
            '../' + packagename + '/src/main.js',
            '../' + packagename + '/assets/*',
            '../' + packagename + '/assets/**/*',
            '../' + packagename + '/core/*',
            '../' + packagename + '/core/**/*',
            '../' + packagename + '/public/checkdebug.txt',
            '../' + packagename + '/.env',
            '../' + packagename + '/.json',
            '../' + packagename + '/src/*',
            '../' + packagename + '/readme.md',
            '../' + packagename + '/index.html',
            '../' + packagename + '/package.json',
        ],
        from: [new RegExp(config.name, "g"), new RegExp(config.name_lower, "g")],
        to: [packagename, packagename.toLowerCase()],
    };
    console.log('Заменяем имя пакета в ../' + packagename);
    try {
        let changedFiles = replace.sync(options);
        console.log('Modified files:', changedFiles);
    } catch (error) {
        console.error('Error occurred:', error);
    }

    console.log('Переименовываем ' + config.name_lower + '.class.php  в ' + packagename.toLowerCase() + '.class.php');
    await fs.promises.rename(
        '../' + packagename + '/core/components/' + config.name_lower + '/model/' + config.name_lower + '.class.php',
        '../' + packagename + '/core/components/' + config.name_lower + '/model/' + packagename.toLowerCase() + '.class.php'
    );
    await fs.promises.rename(
        '../' + packagename + '/core/components/' + config.name_lower + '/model/schema/' + config.name_lower + '.mysql.schema.xml',
        '../' + packagename + '/core/components/' + config.name_lower + '/model/schema/' + packagename.toLowerCase() + '.mysql.schema.xml'
    );
    await fs.promises.rename(
        '../' + packagename + '/core/components/' + config.name_lower,
        '../' + packagename + '/core/components/' + packagename.toLowerCase()
    );
    await fs.promises.rename(
        '../' + packagename + '/assets/components/' + config.name_lower,
        '../' + packagename + '/assets/components/' + packagename.toLowerCase()
    );
}

if (argName) {
    run(argName).catch(err => {
        console.error(err);
        process.exit(1);
    });
} else {
    prompt.start();
    prompt.get(['packagename'], async function (err, result) {
        if (err) { return onErr(err); }
        await run(result.packagename);
    });
}

function onErr(err) {
    console.log(err);
    return 1;
}
