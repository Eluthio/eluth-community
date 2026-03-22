;(function () {
  'use strict'
  window.__EluthPlugins = window.__EluthPlugins || {}
  const { ref, h } = window.Vue

  window.__EluthPlugins['watch-party'] = {
    zones: ['input'],
    component: {
      name: 'WatchPartyPlugin',
      props: {
        settings:      { type: Object, default: () => ({}) },
        apiBase:       { type: String, default: '' },
        authToken:     { type: String, default: '' },
        channelId:     { type: String, default: '' },
        currentMember: { type: Object, default: null },
      },
      emits: ['insert'],
      setup(props, { emit }) {
        const open      = ref(false)
        const proposals = ref([])
        const url       = ref('')
        const title     = ref('')
        const loading   = ref(false)
        const error     = ref('')

        function hdr() {
          return { Authorization: 'Bearer ' + props.authToken, Accept: 'application/json', 'Content-Type': 'application/json' }
        }

        async function load() {
          if (!props.channelId) return
          loading.value = true
          try {
            const r = await fetch(`${props.apiBase}/api/plugins/watch-party/proposals?channel_id=${props.channelId}`, { headers: hdr() })
            proposals.value = (await r.json()).proposals ?? []
          } catch { } finally { loading.value = false }
        }

        async function propose() {
          error.value = ''
          if (!url.value.trim()) { error.value = 'Enter a video URL.'; return }
          try {
            const r = await fetch(`${props.apiBase}/api/plugins/watch-party/proposals`, {
              method: 'POST', headers: hdr(),
              body: JSON.stringify({ channel_id: props.channelId, url: url.value.trim(), title: title.value.trim() || null }),
            })
            if (!r.ok) { error.value = (await r.json()).message ?? 'Failed.'; return }
            url.value = ''; title.value = ''; await load()
          } catch { error.value = 'Network error.' }
        }

        async function vote(id) {
          await fetch(`${props.apiBase}/api/plugins/watch-party/proposals/${id}/vote`, {
            method: 'POST', headers: hdr(), body: '{}',
          })
          await load()
        }

        function toggle() { open.value = !open.value; if (open.value) load() }

        return () => {
          const nodes = [
            h('button', { class: 'input-action plugin-wp-btn', title: 'Watch Party', onClick: toggle }, '\uD83D\uDCFA'),
          ]
          if (open.value) {
            const items = loading.value
              ? [h('div', { class: 'plugin-loading' }, 'Loading\u2026')]
              : proposals.value.length === 0
                ? [h('div', { class: 'plugin-empty' }, 'No proposals yet.')]
                : proposals.value.map(p =>
                    h('div', { key: p.id, class: 'plugin-wp-item' + (p.is_approved ? ' plugin-wp-item--ok' : '') }, [
                      h('div', { class: 'plugin-wp-title' }, p.title || p.url),
                      h('div', { class: 'plugin-wp-meta' }, `by ${p.proposed_by} \u00B7 ${p.votes} vote${p.votes !== 1 ? 's' : ''}${p.is_approved ? ' \u2713' : ''}`),
                      h('button', {
                        class: 'plugin-wp-vote' + (p.voted ? ' plugin-wp-vote--on' : ''),
                        onClick: () => vote(p.id),
                      }, p.voted ? '\u25B2 Voted' : '\u25B2 Vote'),
                    ])
                  )

            nodes.push(
              h('div', { class: 'plugin-panel plugin-wp-panel' }, [
                h('div', { class: 'plugin-wp-list' }, items),
                h('div', { class: 'plugin-wp-form' }, [
                  h('input', { class: 'plugin-field', placeholder: 'Video URL (YouTube, Twitch\u2026)', value: url.value, onInput: e => { url.value = e.target.value } }),
                  h('input', { class: 'plugin-field', placeholder: 'Title (optional)', value: title.value, onInput: e => { title.value = e.target.value } }),
                  error.value && h('div', { class: 'plugin-error' }, error.value),
                  h('button', { class: 'plugin-btn-primary', onClick: propose }, 'Propose'),
                ]),
              ]),
              h('div', { class: 'plugin-backdrop', onClick: () => { open.value = false } })
            )
          }
          return h('div', { class: 'plugin-wp-wrap plugin-wrap' }, nodes)
        }
      },
    },
  }
})()
