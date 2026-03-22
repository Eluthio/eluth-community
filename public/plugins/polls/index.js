;(function () {
  'use strict'
  window.__EluthPlugins = window.__EluthPlugins || {}
  const { ref, h } = window.Vue

  window.__EluthPlugins['polls'] = {
    zones: ['input'],
    component: {
      name: 'PollsPlugin',
      props: {
        settings:      { type: Object, default: () => ({}) },
        apiBase:       { type: String, default: '' },
        authToken:     { type: String, default: '' },
        channelId:     { type: String, default: '' },
        currentMember: { type: Object, default: null },
      },
      emits: ['insert'],
      setup(props, { emit }) {
        const open     = ref(false)
        const poll     = ref(null)
        const loading  = ref(false)
        const creating = ref(false)
        const question = ref('')
        const options  = ref(['', ''])
        const error    = ref('')

        function hdr() {
          return { Authorization: 'Bearer ' + props.authToken, Accept: 'application/json', 'Content-Type': 'application/json' }
        }

        async function loadPoll() {
          if (!props.channelId) return
          loading.value = true
          try {
            const r = await fetch(`${props.apiBase}/api/plugins/polls/active?channel_id=${props.channelId}`, { headers: hdr() })
            poll.value = (await r.json()).poll ?? null
          } catch { } finally { loading.value = false }
        }

        async function createPoll() {
          error.value = ''
          const opts = options.value.map(o => o.trim()).filter(Boolean)
          if (!question.value.trim()) { error.value = 'Enter a question.'; return }
          if (opts.length < 2) { error.value = 'Need at least 2 options.'; return }
          try {
            const r = await fetch(`${props.apiBase}/api/plugins/polls`, {
              method: 'POST', headers: hdr(),
              body: JSON.stringify({ channel_id: props.channelId, question: question.value.trim(), options: opts }),
            })
            if (!r.ok) { error.value = (await r.json()).message ?? 'Failed.'; return }
            question.value = ''; options.value = ['', '']; creating.value = false
            await loadPoll()
          } catch { error.value = 'Network error.' }
        }

        async function vote(optionId) {
          await fetch(`${props.apiBase}/api/plugins/polls/${poll.value.id}/vote`, {
            method: 'POST', headers: hdr(), body: JSON.stringify({ option_id: optionId }),
          })
          await loadPoll()
        }

        async function closePoll() {
          await fetch(`${props.apiBase}/api/plugins/polls/${poll.value.id}/close`, {
            method: 'POST', headers: hdr(), body: '{}',
          })
          await loadPoll()
        }

        function toggle() { open.value = !open.value; if (open.value) loadPoll() }

        return () => {
          const isAdmin = props.currentMember?.isAdmin
          const nodes = [
            h('button', { class: 'input-action plugin-poll-btn', title: 'Polls', onClick: toggle }, '\uD83D\uDCCA'),
          ]
          if (open.value) {
            let content
            if (loading.value) {
              content = h('div', { class: 'plugin-loading' }, 'Loading\u2026')
            } else if (creating.value) {
              content = h('div', { class: 'plugin-poll-form' }, [
                h('input', { class: 'plugin-field', placeholder: 'Question\u2026', value: question.value, onInput: e => { question.value = e.target.value } }),
                ...options.value.map((opt, i) =>
                  h('input', { key: i, class: 'plugin-field', placeholder: `Option ${i + 1}`, value: opt, onInput: e => { options.value[i] = e.target.value } })
                ),
                h('button', { class: 'plugin-btn-ghost', onClick: () => options.value.push('') }, '+ Option'),
                error.value && h('div', { class: 'plugin-error' }, error.value),
                h('div', { class: 'plugin-row' }, [
                  h('button', { class: 'plugin-btn-ghost', onClick: () => { creating.value = false; error.value = '' } }, 'Cancel'),
                  h('button', { class: 'plugin-btn-primary', onClick: createPoll }, 'Create'),
                ]),
              ])
            } else if (poll.value) {
              const p = poll.value
              const total = p.options.reduce((s, o) => s + (o.vote_count ?? 0), 0)
              content = h('div', { class: 'plugin-poll-active' }, [
                h('div', { class: 'plugin-poll-q' }, p.question),
                ...p.options.map(o =>
                  h('button', {
                    key: o.id,
                    class: 'plugin-poll-opt' + (p.my_vote === o.id ? ' plugin-poll-opt--on' : ''),
                    onClick: () => vote(o.id),
                  }, [
                    h('span', {}, o.text),
                    h('span', { class: 'plugin-poll-pct' }, total ? ` ${Math.round((o.vote_count ?? 0) / total * 100)}%` : ' 0%'),
                  ])
                ),
                h('div', { class: 'plugin-poll-meta' }, `${total} vote${total !== 1 ? 's' : ''}`),
                isAdmin && h('button', { class: 'plugin-btn-ghost plugin-poll-close', onClick: closePoll }, 'Close poll'),
              ])
            } else {
              content = h('div', {}, [
                h('div', { class: 'plugin-empty' }, 'No active poll.'),
                h('button', { class: 'plugin-btn-primary', onClick: () => { creating.value = true } }, 'Create Poll'),
              ])
            }

            nodes.push(
              h('div', { class: 'plugin-panel plugin-poll-panel' }, [content]),
              h('div', { class: 'plugin-backdrop', onClick: () => { open.value = false } })
            )
          }
          return h('div', { class: 'plugin-poll-wrap plugin-wrap' }, nodes)
        }
      },
    },
  }
})()
