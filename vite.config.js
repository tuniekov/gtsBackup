import { defineConfig , loadEnv} from 'vite'
import vue from '@vitejs/plugin-vue'
import { parse, resolve } from 'path'
import tailwindcss from 'tailwindcss'

export default defineConfig(async ({mode})=>{
    process.env = {...process.env,...loadEnv(mode, './'),...loadEnv(mode, '../')}
    
    return {
        //appType: 'custom',
        base: mode == 'production' ? process.env.VITE_APP_BASE_URL : '/',
        publicDir: false,
        build: {
            manifest: false,
            copyPublicDir: false,
            minify: true,
            modulePreload: false,
            emptyOutDir: true,
            assetsDir: '',
            cssCodeSplit: true,
            outDir: `${process.env.VITE_APP_ASSETS_DIR}/web/`,
            rollupOptions: {
                external: ['vue',/^pvtables.*/],
                input: {
                    main: resolve(__dirname,'src/main.js')
                },
                output: {
                    assetFileNames: ({name}) => {
                        const {ext} = parse(name)
                        switch(ext){
                            case '.css':
                                return `css/[name][extname]`
                            case '.jpg':
                            case '.png':
                            case '.webp':
                            case '.avif':
                            case '.svg':
                                return `img/[name]-[hash][extname]`
                            default:
                                return `[ext]/[name]-[hash][extname]`
                        }
                    },
                    chunkFileNames: 'js/chunks/[name]-[hash].js',
                    entryFileNames: 'js/[name].js',
                    globals: {
                        vue: 'Vue'
                    }
                }
            }
        },
        plugins: [vue(),tailwindcss()],
        server: {
            proxy: {
                '/api': {
                    target: `${process.env.VITE_APP_PROTOCOL}://${process.env.VITE_APP_HOST}/`,
                    changeOrigin: true,
                    headers: {
                        'Authorization': "Bearer " + process.env.VITE_DEV_TOKEN
                    }
                },
            }
        },
        resolve: {
            alias: {
                'pvtables/dist/pvtables':'pvtables/src/index',
                'vue': 'vue/dist/vue.esm-bundler.js'
            }
        }
    }
})
