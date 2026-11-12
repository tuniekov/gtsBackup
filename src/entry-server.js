import { renderToString } from 'vue/server-renderer'
import { createSSRApp } from 'vue'
import App from './App.vue'
import myPVTables from 'pvtables/dist/pvtables'
/**
 * @param {string} _url
 */
export async function render(_url) {
  const app = createSSRApp(App);
  app.use(myPVTables);
  // passing SSR context object which will be available via useSSRContext()
  // @vitejs/plugin-vue injects code into a component's setup() that registers
  // itself on ctx.modules. After the render, ctx.modules would contain all the
  // components that have been instantiated during this render call.
  const ctx = {}
  const html = await renderToString(app, ctx)

  return { html }
}