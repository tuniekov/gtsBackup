import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import myPVTables from 'pvtables/dist/pvtables'


const app = createApp(App);

app.use(myPVTables);
// app.use(PrimeVue, {
//     theme: {
//         preset: Lara,
//         pt: Lara,
//         options: {
//             darkModeSelector: '.my-app-dark',
//             cssLayer: {
//                 name: 'primevue',
//                 order: 'tailwind-base, primevue, tailwind-utilities'
//             }
//         }
//     }
// })

app.mount('#gtsbackup')