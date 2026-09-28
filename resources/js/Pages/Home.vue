<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{
    shopName: string;
    apiToken: string;
}>();

const fields = [
    { key: 'shopName', label: 'Shop name', value: props.shopName },
    { key: 'apiToken', label: 'API token', value: props.apiToken },
];

const copiedKey = ref<string | null>(null);

async function copy(key: string, value: string) {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        // Clipboard API can be blocked inside the Shopify admin iframe.
        (document.getElementById(key) as HTMLInputElement | null)?.select();
        document.execCommand('copy');
    }

    copiedKey.value = key;
    setTimeout(() => (copiedKey.value = null), 2000);
}
</script>

<template>
    <div class="mx-auto max-w-2xl p-6">
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">API access</h2>
            <p class="mt-1 text-sm text-gray-600">
                Send the token as <code class="rounded bg-gray-100 px-1">Authorization: Bearer &lt;token&gt;</code>
                to call the products API. Keep it secret.
            </p>

            <div v-for="field in fields" :key="field.key" class="mt-4">
                <label :for="field.key" class="block text-sm font-medium text-gray-700">{{ field.label }}</label>
                <div class="mt-1 flex gap-2">
                    <input
                        :id="field.key"
                        :value="field.value"
                        readonly
                        class="min-w-0 flex-1 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-800"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <button
                        type="button"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700"
                        @click="copy(field.key, field.value)"
                    >
                        {{ copiedKey === field.key ? 'Copied' : 'Copy' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
