import './bootstrap';
import Alpine from 'alpinejs';
import scrutiumTour from './components/tour';

window.Alpine = Alpine;

Alpine.data('scrutiumTour', scrutiumTour);

Alpine.start();
