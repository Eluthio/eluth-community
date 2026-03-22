;(function () {
  'use strict'
  window.__EluthPlugins = window.__EluthPlugins || {}
  const { ref, h } = window.Vue

  const plugin = {
    zones: ['input'],
    transformContent: null,
    _emoteMap: {},

    async bootstrap(api) {
      try {
        const data = await api.get('/plugins/emoticons/emotes')
        const map = {}
        for (const e of (data.emotes ?? [])) map[e.name] = e
        plugin._emoteMap = map
        plugin.transformContent = (html) =>
          html.replace(/:([a-z0-9_-]{2,32}):/g, (match, name) => {
            const emote = map[name]
            if (!emote) return match
            return `<img src="${emote.url.replace(/"/g, '&quot;')}" class="msg-emote" alt=":${name}:" title=":${name}:" loading="lazy" />`
          })
      } catch { plugin.transformContent = null }
    },

    component: {
      name: 'EmotePickerPlugin',
      props: {
        settings:      { type: Object, default: () => ({}) },
        apiBase:       { type: String, default: '' },
        authToken:     { type: String, default: '' },
        channelId:     { type: String, default: '' },
        currentMember: { type: Object, default: null },
      },
      emits: ['insert'],
      setup(props, { emit }) {
        const open   = ref(false)
        const filter = ref('')

        function pick(name) { emit('insert', `:${name}:`); open.value = false; filter.value = '' }

        return () => {
          const map   = plugin._emoteMap ?? {}
          const names = Object.keys(map).filter(n => !filter.value || n.includes(filter.value.toLowerCase()))
          const nodes = [
            h('button', { class: 'input-action plugin-emote-btn', title: 'Emotes', onClick: () => { open.value = !open.value; filter.value = '' } }, '\uD83D\uDE0A'),
          ]
          if (open.value) {
            nodes.push(
              h('div', { class: 'plugin-panel plugin-emote-panel' }, [
                h('input', { class: 'plugin-emote-search', placeholder: 'Search emotes\u2026', value: filter.value, onInput: e => { filter.value = e.target.value } }),
                names.length === 0
                  ? h('div', { class: 'plugin-empty' }, filter.value ? 'No results.' : 'No emotes uploaded yet.')
                  : h('div', { class: 'plugin-emote-grid' },
                      names.map(name =>
                        h('img', { key: name, src: map[name].url, class: 'plugin-emote-thumb', title: `:${name}:`, loading: 'lazy', onClick: () => pick(name) })
                      )
                    ),
              ]),
              h('div', { class: 'plugin-backdrop', onClick: () => { open.value = false } })
            )
          }
          return h('div', { class: 'plugin-emote-wrap plugin-wrap' }, nodes)
        }
      },
    },
  }

  window.__EluthPlugins['emoticon-picker'] = plugin
})()
