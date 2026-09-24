{{-- Popup de instalação PWA — incluir antes de </body>: <x-pwa-install /> --}}
<div id="pwa-install-popup" class="hidden fixed inset-x-0 bottom-0 z-50 p-4 sm:inset-x-auto sm:bottom-6 sm:right-6 sm:w-[380px]" role="dialog" aria-modal="true" aria-labelledby="pwa-install-title">
  <div class="bg-white dark:bg-[#161615] rounded-2xl shadow-2xl border border-black/10 dark:border-white/10 p-5 flex gap-4 items-start">
    <img src="/icons/icon-192x192.png" alt="Ícone do app" class="w-14 h-14 rounded-xl shrink-0" />
    <div class="flex-1 min-w-0">
      <p id="pwa-install-title" class="font-semibold text-[15px] leading-tight">Instale o app Quadras</p>
      <p id="pwa-install-text" class="text-[13px] text-gray-500 dark:text-gray-400 mt-1 leading-snug">
        Acesso rápido, tela cheia e funciona melhor instalado na sua tela inicial.
      </p>
      <p id="pwa-install-ios-text" class="hidden text-[13px] text-gray-500 dark:text-gray-400 mt-1 leading-snug">
        No iPhone: toque em <strong>Compartilhar</strong> <span aria-hidden="true">⎙</span> e depois em <strong>“Adicionar à Tela de Início”</strong>.
      </p>
      <div class="flex gap-2 mt-3">
        <button id="pwa-install-btn" type="button" class="px-4 py-2 rounded-lg bg-[#16a34a] hover:bg-[#15803d] text-white text-sm font-medium">
          Instalar agora
        </button>
        <button id="pwa-install-dismiss" type="button" class="px-4 py-2 rounded-lg text-sm text-gray-500 hover:bg-black/5 dark:hover:bg-white/10">
          Agora não
        </button>
      </div>
    </div>
    <button id="pwa-install-close" type="button" aria-label="Fechar" class="text-gray-400 hover:text-gray-600 text-lg leading-none px-1">×</button>
  </div>
</div>
