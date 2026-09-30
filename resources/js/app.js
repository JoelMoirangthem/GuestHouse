import Alpine from 'alpinejs';
import { startCsrfRefresh } from './csrf-refresh';

window.Alpine = Alpine;
Alpine.start();

startCsrfRefresh();
