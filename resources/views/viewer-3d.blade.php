<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>3D Model Viewer</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #0f1117; color: #fff; font-family: system-ui, sans-serif; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
#toolbar { padding: 10px 16px; background: rgba(255,255,255,.04); border-bottom: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; gap: 12px; font-size: 13px; flex-shrink: 0; }
#toolbar a { color: #22d3ee; text-decoration: none; font-size: 12px; }
#model-name { color: rgba(255,255,255,.6); font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 300px; }
#canvas-wrap { flex: 1; position: relative; }
canvas { display: block; width: 100% !important; height: 100% !important; }
#hint { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); font-size: 11px; color: rgba(255,255,255,.3); pointer-events: none; }
#error { display: none; position: absolute; inset: 0; align-items: center; justify-content: center; flex-direction: column; gap: 8px; color: rgba(255,255,255,.5); font-size: 14px; }
#error.show { display: flex; }
#loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 10px; color: rgba(255,255,255,.4); font-size: 13px; }
.spinner { width: 28px; height: 28px; border: 3px solid rgba(255,255,255,.1); border-top-color: #22d3ee; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div id="toolbar">
    <span style="font-weight:700;color:#22d3ee">📦 3D Viewer</span>
    <span id="model-name"></span>
    <span style="flex:1"></span>
    <span id="model-info" style="color:rgba(255,255,255,.35);font-size:11px;font-family:monospace"></span>
</div>
<div id="canvas-wrap">
    <div id="loading"><div class="spinner"></div><span>Loading model…</span></div>
    <div id="error"><span>⚠️</span><span id="error-msg">Could not load model.</span></div>
    <div id="hint">Drag to rotate · Scroll to zoom · Right-drag to pan</div>
</div>

<script type="importmap">
{
    "imports": {
        "three": "https://cdn.jsdelivr.net/npm/three@0.164.1/build/three.module.js",
        "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.164.1/examples/jsm/"
    }
}
</script>
<script type="module">
import * as THREE from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import { OBJLoader }     from 'three/addons/loaders/OBJLoader.js'
import { STLLoader }     from 'three/addons/loaders/STLLoader.js'
import { GLTFLoader }    from 'three/addons/loaders/GLTFLoader.js'

const params = new URLSearchParams(location.search)
const modelUrl = params.get('model') || ''

if (!modelUrl) {
    showError('No model URL provided.')
} else {
    document.getElementById('model-name').textContent = modelUrl.split('/').pop()
    initViewer(modelUrl)
}

function showError(msg) {
    document.getElementById('loading').style.display = 'none'
    const el = document.getElementById('error')
    el.classList.add('show')
    document.getElementById('error-msg').textContent = msg
}

function initViewer(url) {
    const wrap   = document.getElementById('canvas-wrap')
    const renderer = new THREE.WebGLRenderer({ antialias: true })
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    renderer.setSize(wrap.clientWidth, wrap.clientHeight)
    renderer.shadowMap.enabled = true
    wrap.appendChild(renderer.domElement)

    const scene  = new THREE.Scene()
    scene.background = new THREE.Color(0x0f1117)

    const camera = new THREE.PerspectiveCamera(45, wrap.clientWidth / wrap.clientHeight, 0.01, 10000)
    camera.position.set(0, 1, 3)

    const controls = new OrbitControls(camera, renderer.domElement)
    controls.enableDamping = true

    // Lighting
    const ambient = new THREE.AmbientLight(0xffffff, 0.5)
    scene.add(ambient)
    const dir1 = new THREE.DirectionalLight(0xffffff, 1)
    dir1.position.set(5, 10, 7)
    scene.add(dir1)
    const dir2 = new THREE.DirectionalLight(0x8ecaff, 0.3)
    dir2.position.set(-5, -5, -5)
    scene.add(dir2)

    // Grid
    const grid = new THREE.GridHelper(10, 20, 0x222233, 0x1a1a2a)
    scene.add(grid)

    const ext = url.split('.').pop().toLowerCase()

    function onLoad(object) {
        document.getElementById('loading').style.display = 'none'

        // Normalise: GLTF returns a scene object
        const mesh = (ext === 'glb' || ext === 'gltf') ? object.scene : object

        // Centre and fit
        const box    = new THREE.Box3().setFromObject(mesh)
        const size   = box.getSize(new THREE.Vector3())
        const centre = box.getCenter(new THREE.Vector3())
        const maxDim = Math.max(size.x, size.y, size.z)
        const scale  = 2 / maxDim

        mesh.position.sub(centre)
        mesh.position.y += size.y * scale / 2

        const group = new THREE.Group()
        group.scale.setScalar(scale)
        group.add(mesh)
        scene.add(group)

        // Default material if geometry has none
        mesh.traverse(child => {
            if (child.isMesh && !child.material) {
                child.material = new THREE.MeshStandardMaterial({ color: 0x22d3ee })
            }
            if (child.isMesh && child.material) {
                child.castShadow    = true
                child.receiveShadow = true
            }
        })

        // Fit camera
        camera.position.set(0, maxDim * scale, maxDim * scale * 2)
        controls.target.set(0, size.y * scale / 2, 0)
        controls.update()

        // Stats
        let triCount = 0
        mesh.traverse(c => { if (c.isMesh && c.geometry) triCount += (c.geometry.index ? c.geometry.index.count : c.geometry.attributes.position.count) / 3 })
        document.getElementById('model-info').textContent = `${Math.round(triCount).toLocaleString()} triangles`
    }

    if (ext === 'obj') {
        new OBJLoader().load(url, onLoad, undefined, () => showError('Failed to load OBJ model.'))
    } else if (ext === 'stl') {
        new STLLoader().load(url, geo => {
            const mat  = new THREE.MeshStandardMaterial({ color: 0x22d3ee, metalness: 0.3, roughness: 0.6 })
            onLoad(new THREE.Mesh(geo, mat))
        }, undefined, () => showError('Failed to load STL model.'))
    } else if (ext === 'glb' || ext === 'gltf') {
        new GLTFLoader().load(url, onLoad, undefined, () => showError('Failed to load GLTF model.'))
    } else {
        showError('Unsupported format: ' + ext)
    }

    // Resize
    window.addEventListener('resize', () => {
        camera.aspect = wrap.clientWidth / wrap.clientHeight
        camera.updateProjectionMatrix()
        renderer.setSize(wrap.clientWidth, wrap.clientHeight)
    })

    // Render loop
    ;(function animate() {
        requestAnimationFrame(animate)
        controls.update()
        renderer.render(scene, camera)
    })()
}
</script>
</body>
</html>
