;(function () {
  'use strict'
  window.__EluthPlugins = window.__EluthPlugins || {}
  const { ref, h } = window.Vue

  window.__EluthPlugins['image-uploader'] = {
    zones: ['input'],
    component: {
      name: 'ImageUploaderPlugin',
      props: {
        settings:      { type: Object, default: () => ({}) },
        apiBase:       { type: String, default: '' },
        authToken:     { type: String, default: '' },
        channelId:     { type: String, default: '' },
        currentMember: { type: Object, default: null },
      },
      emits: ['insert'],
      setup(props, { emit }) {
        const uploading = ref(false)
        const fileInput = ref(null)
        const err       = ref('')

        async function upload(e) {
          const file = e.target.files?.[0]
          if (!file) return
          uploading.value = true; err.value = ''
          try {
            const form = new FormData()
            form.append('file', file)
            const r = await fetch(`${props.apiBase}/api/plugins/image-uploader/upload`, {
              method: 'POST',
              headers: { Authorization: 'Bearer ' + props.authToken },
              body: form,
            })
            const d = await r.json()
            if (!r.ok) { err.value = d.message ?? 'Upload failed.'; return }
            emit('insert', d.url)
          } catch { err.value = 'Upload failed.' } finally {
            uploading.value = false
            if (fileInput.value) fileInput.value.value = ''
          }
        }

        return () => h('div', { class: 'plugin-img-wrap plugin-wrap' }, [
          h('button', {
            class: 'input-action plugin-img-btn',
            title: err.value || 'Upload Image',
            style: err.value ? 'color:#f87171' : '',
            disabled: uploading.value,
            onClick: () => fileInput.value?.click(),
          }, uploading.value ? '\u23F3' : '\uD83D\uDDBC'),
          h('input', { ref: fileInput, type: 'file', accept: 'image/*', style: 'display:none', onChange: upload }),
        ])
      },
    },
  }
})()
