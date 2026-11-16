import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';
import { animate } from 'motion';
import {
	ArrowLeft,
	ArrowRight,
	CarFront,
	Camera,
	ChevronDown,
	ClipboardList,
	CircleAlert,
	CircleCheck,
	Eye,
	EyeOff,
	Info,
	LayoutDashboard,
	ArrowUpRight,
	LogOut,
	LockKeyhole,
	List,
	Mail,
	Menu,
	Plus,
	Search,
	ShieldCheck,
	TriangleAlert,
	Users,
	Wrench,
	X,
	createIcons,
} from 'lucide';

Alpine.plugin(focus);
Alpine.plugin(collapse);
window.Alpine = Alpine;
Alpine.start();

document.addEventListener('click', (event) => {
	const button = event.target.closest('[data-back-button]');
	if (!button) {
		return;
	}

	event.preventDefault();

	const fallback = button.dataset.fallback;
	const currentPath = window.location.pathname;
	let referrer = '';

	try {
		referrer = document.referrer ? new URL(document.referrer).href : '';
	} catch {
		referrer = '';
	}

	const sameOrigin = referrer !== '' && new URL(referrer).origin === window.location.origin;
	const notCurrent = referrer !== '' && new URL(referrer).pathname !== window.location.pathname;
	let fallbackUrl = null;
	try {
		fallbackUrl = fallback ? new URL(fallback, window.location.origin) : null;
	} catch {
		fallbackUrl = null;
	}
	const safeFallback = fallbackUrl !== null && fallbackUrl.origin === window.location.origin && fallbackUrl.pathname !== currentPath;
	const notFallbackLoop = !safeFallback || new URL(referrer).pathname !== fallbackUrl.pathname;

	if (sameOrigin && notCurrent && notFallbackLoop && window.history.length > 1) {
		window.history.back();
		return;
	}

	if (safeFallback) {
		window.location.assign(fallbackUrl.href);
	}
});

document.addEventListener('submit', (event) => {
	const completedForm = event.target.matches('[data-completed="true"]');
	if (completedForm) {
		event.preventDefault();
		return;
	}
	const submitButton = event.target.querySelector('[data-submit-diagnosis]');
	if (!submitButton) {
		return;
	}
	submitButton.disabled = true;
	submitButton.setAttribute('aria-disabled', 'true');
	submitButton.classList.add('cursor-wait', 'opacity-60');
	submitButton.textContent = 'Guardando...';
});

const checkProcessedDiagnosisForm = async (form) => {
	if (!form || form.dataset.statusChecked === 'true' || form.dataset.statusChecking === 'true') {
		return;
	}

	const statusUrl = form.dataset.statusUrl;
	const envioUuid = form.dataset.envioUuid;
	if (!statusUrl || !envioUuid) {
		return;
	}
	form.dataset.statusChecking = 'true';

	const statusIndicator = document.getElementById('diagnosis-check-status');
	const completedAlert = document.getElementById('diagnosis-completed-alert');
	if (statusIndicator) {
		statusIndicator.textContent = 'Comprobando si este formulario ya fue procesado...';
		statusIndicator.className = 'mb-4 rounded-lg border border-seibi-border bg-seibi-workspace px-4 py-3 text-sm text-slate-600';
		statusIndicator.classList.remove('hidden');
	}

	const url = new URL(window.location.href);
	url.searchParams.set('envio_uuid', envioUuid);
	window.history.replaceState(window.history.state, '', url);

	try {
		const controller = new AbortController();
		const timeout = window.setTimeout(() => controller.abort(), 8000);
		const response = await fetch(statusUrl, {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json' },
			signal: controller.signal,
		});
		window.clearTimeout(timeout);

		if (!response.ok) {
			throw new Error('No se pudo consultar el estado del formulario.');
		}

		const result = await response.json();

		if (statusIndicator) {
			statusIndicator.classList.add('hidden');
		}

		if (!result.processed || !result.diagnosis) {
			form.dataset.statusChecking = 'false';
			return;
		}
		form.dataset.statusChecked = 'true';
		form.dataset.statusChecking = 'false';

		const diagnosis = result.diagnosis;
		if (!form.elements.titulo.value) {
			form.elements.titulo.value = diagnosis.titulo ?? '';
		}
		if (!form.elements.descripcion.value) {
			form.elements.descripcion.value = diagnosis.descripcion ?? '';
		}
		if (!form.elements.recomendacion.value) {
			form.elements.recomendacion.value = diagnosis.recomendacion ?? '';
		}
		if (!form.elements.severidad.value && diagnosis.severidad) {
			form.elements.severidad.value = diagnosis.severidad;
		}

		form.dataset.completed = 'true';
		form.querySelectorAll('input, select, textarea, button[type="submit"]').forEach((control) => {
			control.disabled = true;
			control.setAttribute('aria-disabled', 'true');
		});

		if (completedAlert) {
			completedAlert.classList.remove('hidden');
			const registeredLink = document.getElementById('diagnosis-registered-link');
			if (registeredLink && diagnosis.url) {
				registeredLink.href = diagnosis.url;
			}
			completedAlert.scrollIntoView({ block: 'start', behavior: 'smooth' });
		}
	} catch {
		form.dataset.statusChecking = 'false';
		if (statusIndicator) {
			statusIndicator.textContent = 'No fue posible comprobar el estado. Conserva esta página hasta que la conexión esté disponible.';
			statusIndicator.className = 'mb-4 rounded-lg border border-seibi-border bg-seibi-workspace px-4 py-3 text-sm text-slate-600';
		}
	}
};

const initializeDiagnosisSubmissionCheck = () => {
	const form = document.getElementById('diagnosis-create-form');
	checkProcessedDiagnosisForm(form);
};

window.addEventListener('DOMContentLoaded', initializeDiagnosisSubmissionCheck);
window.addEventListener('pageshow', initializeDiagnosisSubmissionCheck);

createIcons({
	icons: {
		ArrowLeft,
		ArrowRight,
		CarFront,
		Camera,
		ChevronDown,
		ClipboardList,
		CircleAlert,
		CircleCheck,
		Eye,
		EyeOff,
		Info,
		LayoutDashboard,
		ArrowUpRight,
		LogOut,
		LockKeyhole,
		List,
		Mail,
		Menu,
		Plus,
		Search,
		ShieldCheck,
		TriangleAlert,
		Users,
		Wrench,
		X,
	},
});

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
	document.querySelectorAll('[data-motion="enter"]').forEach((element) => {
		animate(element, { opacity: [0, 1], y: [8, 0] }, { duration: 0.22, easing: 'ease-out' });
	});
}
