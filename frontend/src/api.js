let csrf = '';
let sessionExpiredHandler = null;  // ← переименовали переменную

export function setCsrf(value) {
    csrf = value;
}

// Регистрируем обработчик потери сессии (вызывается из App.jsx)
export function onSessionExpired(handler) {
    sessionExpiredHandler = handler;  // ← используем новое имя
}

export async function api(path, options = {}) {
    const response = await fetch(`/api${path}`, {
        credentials: 'same-origin',
        ...options,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...options.headers },
    });

    // Глобальная обработка потери сессии
    if (response.status === 401) {
        // Уведомляем React — он сам сбросит стейт и покажет форму логина
        if (sessionExpiredHandler) {  // ← используем новое имя
            sessionExpiredHandler();
        }
        throw new Error('Сессия истекла');
    }

    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error(`HTTP ${response.status}: некорректный ответ сервера`);
    }

    if (!response.ok) {
        const error = new Error(data.message || `HTTP ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return data;
}