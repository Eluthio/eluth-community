;(function () {
  'use strict'
  window.__EluthPlugins = window.__EluthPlugins || {}
  const { ref, h } = window.Vue

  window.__EluthPlugins['gif-picker'] = {
    zones: ['input'],
    component: {
      name: 'GifPickerPlugin',
      props: {
        settings:      { type: Object, default: () => ({}) },
        apiBase:       { type: String, default: '' },
        authToken:     { type: String, default: '' },
        channelId:     { type: String, default: '' },
        currentMember: { type: Object, default: null },
      },
      emits: ['insert'],
      setup(props, { emit }) {
        const open    = ref(false)
        const query   = ref('')
        const gifs    = ref([])
        const loading = ref(false)

        function hdr() { return { Authorization: 'Bearer ' + props.authToken, Accept: 'application/json' } }

        async function load(url) {
          loading.value = true
          try {
            const r = await fetch(props.apiBase + url, { headers: hdr() })
            const d = await r.json()
            gifs.value = d.gifs ?? []
          } catch { gifs.value = [] } finally { loading.value = false }
        }

        function toggle() {
          open.value = !open.value
          if (open.value && !gifs.value.length) load('/api/plugins/gif-picker/trending')
        }

        function search() {
          const q = query.value.trim()
          load(q ? `/api/plugins/gif-picker/search?q=${encodeURIComponent(q)}` : '/api/plugins/gif-picker/trending')
        }

        function pick(gif) { emit('insert', gif.url); open.value = false; query.value = '' }

        return () => {
          const nodes = [
            h('button', { class: 'input-action plugin-gif-btn', title: 'GIF', onClick: toggle }, 'GIF'),
          ]
          if (open.value) {
            nodes.push(
              h('div', { class: 'plugin-panel plugin-gif-panel' }, [
                h('div', { class: 'plugin-gif-search-row' }, [
                  h('input', {
                    class: 'plugin-gif-search',
                    placeholder: 'Search GIFs\u2026',
                    value: query.value,
                    onInput: e => { query.value = e.target.value },
                    onKeydown: e => { if (e.key === 'Enter') search() },
                  }),
                  h('button', { class: 'plugin-gif-go', onClick: search }, '\uD83D\uDD0D'),
                ]),
                loading.value
                  ? h('div', { class: 'plugin-loading' }, 'Loading\u2026')
                  : h('div', { class: 'plugin-gif-grid' },
                      gifs.value.map((g, i) =>
                        h('img', {
                          key: g.id ?? i,
                          src: g.preview ?? g.url,
                          class: 'plugin-gif-thumb',
                          loading: 'lazy',
                          title: g.title ?? '',
                          onClick: () => pick(g),
                        })
                      )
                    ),
              ]),
              h('div', { class: 'plugin-backdrop', onClick: () => { open.value = false } })
            )
          }
          return h('div', { class: 'plugin-gif-wrap plugin-wrap' }, nodes)
        }
      },
    },
  }
})()
