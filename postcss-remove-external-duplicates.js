import { readFileSync, writeFileSync } from 'fs';
import { resolve } from 'path';
import postcss from 'postcss';

/**
 * Нормализация селектора для сравнения
 */
function normalizeSelector(selector) {
    return selector
        .replace(/\s+/g, ' ')     // Заменяем множественные пробелы на один
        .replace(/\n/g, '')       // Удаляем переносы строк
        .trim()                    // Убираем пробелы по краям
        .replace(/,\s*/g, ',')    // Убираем пробелы после запятых
        .replace(/\s*,/g, ',')    // Убираем пробелы перед запятыми
        .replace(/::?before/g, '::before')  // Нормализуем :before -> ::before
        .replace(/::?after/g, '::after');   // Нормализуем :after -> ::after
}

/**
 * Нормализация значения CSS для сравнения
 */
function normalizeValue(value) {
    return value
        .replace(/\s+/g, ' ')     // Множественные пробелы в один
        .replace(/\s*\/\s*/g, '/')  // Убираем пробелы вокруг /
        .replace(/(\d)\s*\.\s*/g, '$1.')  // Нормализуем пробелы вокруг точки
        .replace(/\b0\.(\d)/g, '.$1')     // 0.5 -> .5
        .trim();
}

/**
 * PostCSS плагин для удаления CSS правил, которые уже есть во внешнем файле
 * @param {Object} opts - опции плагина
 * @param {string} opts.externalCssPath - путь к внешнему CSS файлу для сравнения
 * @param {boolean} opts.debug - включить режим отладки (создание debug-*.txt файлов)
 */
export default function removeExternalDuplicates(opts = {}) {
    return {
        postcssPlugin: 'postcss-remove-external-duplicates',
        
        Once(root, { result }) {
            if (!opts.externalCssPath) {
                return;
            }

            try {
                // Читаем внешний CSS файл
                const externalCssPath = resolve(process.cwd(), opts.externalCssPath);
                const externalCss = readFileSync(externalCssPath, 'utf8');
                
                // Парсим внешний CSS
                const externalRoot = postcss.parse(externalCss);
                
                // Собираем хеши правил из внешнего файла (селектор + декларации)
                const externalRules = new Map();
                let totalExternalRules = 0;
                
                externalRoot.walkRules(rule => {
                    totalExternalRules++;
                    // Создаем уникальный хеш правила на основе селектора и деклараций
                    const normalizedSelector = normalizeSelector(rule.selector);
                    const declarations = [];
                    rule.walkDecls(decl => {
                        const normalizedValue = normalizeValue(decl.value);
                        declarations.push(`${decl.prop}:${normalizedValue}`);
                    });
                    declarations.sort(); // Сортируем для стабильности
                   const ruleHash = `${normalizedSelector}|${declarations.join('|')}`;
                    externalRules.set(ruleHash, true);
                });
                
                if (opts.debug) {
                    console.log(`[postcss-remove-external-duplicates] Найдено ${totalExternalRules} правил во внешнем файле`);
                }
                
                // Находим дубликаты
                let removedCount = 0;
                const duplicatesInfo = [];
                const removedSelectors = new Set();
                
                // Удаляем правила, полностью совпадающие с внешним файлом
                root.walkRules(rule => {
                    const normalizedSelector = normalizeSelector(rule.selector);
                    const declarations = [];
                    rule.walkDecls(decl => {
                        const normalizedValue = normalizeValue(decl.value);
                        declarations.push(`${decl.prop}:${normalizedValue}`);
                    });
                    declarations.sort();
                    const ruleHash = `${normalizedSelector}|${declarations.join('|')}`;
                    
                    if (externalRules.has(ruleHash)) {
                        removedSelectors.add(rule.selector);
                        if (opts.debug) {
                            duplicatesInfo.push(`ПОЛНОЕ СОВПАДЕНИЕ: ${rule.selector}`);
                        }
                        rule.remove();
                        removedCount++;
                    }
                });
                
                // Сохраняем debug файлы только если включен режим отладки
                if (opts.debug) {
                    const externalSelectorsFile = resolve(process.cwd(), 'debug-external-selectors.txt');
                    const currentSelectorsFile = resolve(process.cwd(), 'debug-current-selectors.txt');
                    const duplicatesFile = resolve(process.cwd(), 'debug-duplicates.txt');
                    
                    // Сохраняем внешние селекторы
                    const externalContent = Array.from(externalRules.keys()).join('\n');
                    writeFileSync(externalSelectorsFile, externalContent);
                    
                    // Сохраняем текущие селекторы (пересобираем после удаления)
                    const currentRules = [];
                    root.walkRules(rule => {
                        const normalizedSelector = normalizeSelector(rule.selector);
                        const declarations = [];
                        rule.walkDecls(decl => {
                            const normalizedValue = normalizeValue(decl.value);
                            declarations.push(`${decl.prop}:${normalizedValue}`);
                        });
                        declarations.sort();
                        currentRules.push(`${normalizedSelector}|${declarations.join('|')}`);
                    });
                    writeFileSync(currentSelectorsFile, currentRules.join('\n'));
                    
                    // Сохраняем информацию о дубликатах
                    writeFileSync(duplicatesFile, duplicatesInfo.join('\n'));
                    
                    console.log(`[postcss-remove-external-duplicates] Удалено ${removedCount} дублирующихся правил`);
                    console.log(`[postcss-remove-external-duplicates] Debug файлы:`);
                    console.log(`  - debug-external-selectors.txt`);
                    console.log(`  - debug-current-selectors.txt`);
                    console.log(`  - debug-duplicates.txt`);
                } else {
                    if (removedCount > 0) {
                        console.log(`[postcss-remove-external-duplicates] Удалено ${removedCount} дублирующихся правил`);
                    }
                }
                
            } catch (error) {
                console.error('[postcss-remove-external-duplicates] Ошибка:', error.message);
            }
        }
    };
}

removeExternalDuplicates.postcss = true;
