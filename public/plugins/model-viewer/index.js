;(function () {
  'use strict'
  window.__EluthPlugins = window.__EluthPlugins || {}
  const { ref, h } = window.Vue

  const MODEL_PATTERN = /https?:\/\/\S+\/storage\/uploads\/models\/[^\s"<]+\.(obj|stl|glb|gltf)(\?[^\s"<]*)?/i

  window.__EluthPlugins['model-viewer'] = {
    zones: ['input'],
    messageRenderer: {
      pattern: MODEL_PATTERN,
      render(url) {
        try {
          const allowDl  = url.includes('?dl=1')
          const cleanUrl = url.split('?')[0]
          const filename = cleanUrl.split('/').pop()
          const viewerSrc = `/storage/plugins/model-viewer/viewer.html?url=${encodeURIComponent(cleanUrl)}`
          let html = `<span class="msg-model-viewer">`
          html += `<iframe src="${viewerSrc}" class="msg-model-iframe" frameborder="0" allowfullscreen loading="lazy" title="3D Model \u2014 ${filename}"></iframe>`
          html += `<span class="msg-model-footer"><span class="msg-model-icon">\uD83D\uDCE6</span><span class="msg-model-name">${filename}</span>`
          if (allowDl) html += ` <a href="${cleanUrl}" download="${filename}" class="msg-model-dl" title="Download">\u2193</a>`
          html += `</span></span>`
          return html
        } catch { return null }
      },
    },
    component: {
      name: 'ModelViewerPlugin',
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
            form.append('allow_download', '1')
            const r = await fetch(`${props.apiBase}/api/plugins/model-viewer/upload`, {
              method: 'POST',
              headers: { Authorization: 'Bearer ' + props.authToken },
              body: form,
            })
            const d = await r.json()
            if (!r.ok) { err.value = d.message ?? 'Upload failed.'; return }
            emit('insert', d.url + '?dl=1')
          } catch { err.value = 'Upload failed.' } finally {
            uploading.value = false
            if (fileInput.value) fileInput.value.value = ''
          }
        }

        return () => h('div', { class: 'plugin-model-wrap plugin-wrap' }, [
          h('button', {
            class: 'input-action plugin-model-btn',
            title: err.value || 'Upload 3D Model',
            style: err.value ? 'color:#f87171' : '',
            disabled: uploading.value,
            onClick: () => fileInput.value?.click(),
          }, uploading.value ? '\u23F3' : '\uD83D\uDCE6'),
          h('input', { ref: fileInput, type: 'file', accept: '.obj,.stl,.glb,.gltf', style: 'display:none', onChange: upload }),
        ])
      },
    },
  }
})()
