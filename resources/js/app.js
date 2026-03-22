import * as Vue from 'vue'
import { createApp } from 'vue'
import App from './App.vue'
import '../css/app.css'

// Expose Vue globally so plugin IIFE bundles can use it without bundling their own copy.
window.Vue = Vue

createApp(App).mount('#app')
