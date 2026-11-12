import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';
import removeExternalDuplicates from './postcss-remove-external-duplicates.js';

export default {
  plugins: [
    tailwindcss(),
    autoprefixer(),
    removeExternalDuplicates({
      externalCssPath: 'node_modules/pvtables/dist/pvtables.css',
      debug: false  // Установите true для вывода debug файлов
    })
  ],
}
