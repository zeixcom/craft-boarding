/**
 * Boarding - Modern Tour Management System
 *
 * A comprehensive tour management system for Craft CMS.
 * Handles tour initialization, display, and completion tracking.
 *
 * @version 2.0.0
 */
class BoardingManager {
    constructor(settings = {}) {
        this.settings = {
            buttonPosition: 'header',
            buttonLabel: 'Available Tours',
            nextButtonText: 'Next',
            backButtonText: 'Back',
            doneButtonText: 'Done',
            currentSiteId: null,
            currentSiteHandle: null,
            ...settings
        };

        this.initialized = false;
        this.cachedTours = [];
        this.currentMenu = null;
        this.currentTour = null;
    }

    /**
     * Check if initialized (backward compatibility)
     */
    isInitialized() {
        return this.initialized;
    }

    /**
     * Set initialized state (backward compatibility)
     */
    setInitialized(value) {
        this.initialized = value;
    }

    /**
     * Initialize the tour system
     * Can also be called as initTours() for backward compatibility
     */
    async init() {
        if (this.initialized) {
            return;
        }

        if (typeof Shepherd === 'undefined') {
            console.error('Boarding: Shepherd.js is not loaded');
            return;
        }

        this.initialized = true;

        // Check for stored tour state
        const storedTourState = localStorage.getItem('boarding-current-tour');
        const urlParams = new URLSearchParams(window.location.search);
        const autoStartTourId = urlParams.get('startTour');

        try {
            console.log('Boarding: Starting initialization');
            const tours = await this.loadTours();
            this.cachedTours = tours;
            console.log('Boarding: Cached tours set', this.cachedTours);

            // Add tours button to UI
            if (this.settings.buttonPosition !== 'none') {
                console.log('Boarding: Adding tours button with', tours.length, 'tours');
                this.addToursButton(tours);
            }

            // Handle auto-start tour
            if (autoStartTourId && tours.length > 0) {
                this.handleAutoStartTour(autoStartTourId, tours);
            }
            // Handle resuming tour from localStorage
            else if (storedTourState && tours.length > 0) {
                this.handleResumeTour(storedTourState, tours);
            }
            // Handle autoplay tours
            else if (tours.length > 0) {
                this.handleAutoplayTour(tours);
            }
        } catch (error) {
            console.error('Boarding: Failed to initialize tours', error);
            if (this.settings.buttonPosition !== 'none') {
                this.addToursButton([]);
            }
        }
    }

    /**
     * Initialize tours (backward compatibility alias for init)
     */
    async initTours() {
        return this.init();
    }

    /**
     * Load tours from the server
     */
    async loadTours() {
        let url = Craft.getActionUrl('boarding/tours/get-tours-for-current-user');

        // Add site parameter if in multi-site setup
        if (this.settings.currentSiteHandle) {
            url += (url.includes('?') ? '&' : '?') + 'site=' + encodeURIComponent(this.settings.currentSiteHandle);
        }

        console.log('Boarding: Loading tours from', url);

        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': Craft.csrfTokenValue,
            },
            credentials: 'same-origin',
        });

        const data = await response.json();
        console.log('Boarding: Received tour data', data);
        console.log('Boarding: data.success =', data.success);
        console.log('Boarding: data.tours =', data.tours);
        console.log('Boarding: typeof data.tours =', typeof data.tours);
        const tours = data.success ? (data.tours || []) : [];
        console.log('Boarding: Parsed tours', tours);
        return tours;
    }

    /**
     * Reload tours from server
     */
    async reloadTours() {
        this.cachedTours = await this.loadTours();
        return this.cachedTours;
    }

    /**
     * Handle auto-start tour from URL parameter
     */
    handleAutoStartTour(tourId, tours) {
        const tour = this.findTourById(tours, tourId);

        if (tour) {
            // Remove the parameter from URL
            const newUrl = window.location.pathname + (window.location.search.replace(/[?&]startTour=[^&]+/, '') || '');
            window.history.replaceState({}, document.title, newUrl);

            // Clear any stored state
            localStorage.removeItem('boarding-current-tour');
            localStorage.removeItem('boarding-redirect-count');

            setTimeout(() => this.startTour(tour), 500);
        } else if (Craft.cp) {
            Craft.cp.displayError('Could not find the requested tour. Please check if the tour exists and you have permission to view it.');
        }
    }

    /**
     * Handle resuming a tour from localStorage
     */
    handleResumeTour(storedState, tours) {
        try {
            const tourState = JSON.parse(storedState);

            if (tourState && tourState.tourId) {
                const tour = this.findTourById(tours, tourState.tourId);

                if (tour) {
                    let startAt = 0;
                    if (typeof tourState.nextStep === 'number') {
                        startAt = tourState.nextStep;
                    } else if (typeof tourState.currentStep === 'number') {
                        startAt = tourState.currentStep;
                    }

                    if (startAt >= tour.steps.length) {
                        startAt = 0;
                    }

                    localStorage.removeItem('boarding-current-tour');
                    setTimeout(() => this.startTour(tour, startAt), 500);
                } else {
                    localStorage.removeItem('boarding-current-tour');
                }
            }
        } catch (error) {
            console.error('Boarding: Error resuming tour', error);
            localStorage.removeItem('boarding-current-tour');
        }
    }

    /**
     * Handle autoplay tour
     */
    handleAutoplayTour(tours) {
        const tourToStart = tours.find(tour => {
            const isCompleted = tour.completed === true ||
                (tour.completedBy && tour.completedBy.some(completion => completion.username === Craft.username));
            return !isCompleted && tour.autoplay === true;
        });

        if (tourToStart) {
            setTimeout(() => this.startTour(tourToStart), 500);
        }
    }

    /**
     * Start a tour
     */
    startTour(tourData, startAtStep = null) {
        if (!tourData || !tourData.steps || tourData.steps.length === 0) {
            return null;
        }

        if (typeof Shepherd === 'undefined') {
            return null;
        }

        const tour = new Shepherd.Tour({
            defaultStepOptions: {
                cancelIcon: {
                    enabled: true,
                },
                classes: 'boarding-tours-step boarding-plugin',
                scrollTo: { behavior: 'smooth', block: 'center' },
                arrow: true,
            },
            useModalOverlay: true,
        });

        // Set up tour event handlers
        this.setupTourEvents(tour, tourData);

        // Set up progress indicator if enabled
        if (tourData.progressPosition && tourData.progressPosition !== 'off') {
            this.setupProgressIndicator(tour, tourData);
        }

        // Add all steps to the tour
        this.addStepsToTour(tour, tourData);

        // Start the tour
        try {
            if (startAtStep !== null) {
                tour.start();
                tour.show(startAtStep);
            } else {
                tour.start();
            }
            this.currentTour = tour;
            return tour;
        } catch (error) {
            console.error('Boarding: Error starting tour', error);
            return null;
        }
    }

    /**
     * Set up tour event handlers
     */
    setupTourEvents(tour, tourData) {
        tour.on('start', () => {
            const overlayEl = document.querySelector('.shepherd-modal-overlay-container');
            if (overlayEl) {
                overlayEl.classList.add('boarding-plugin');
            }

            setTimeout(() => {
                document.querySelectorAll('.shepherd-element').forEach(el => {
                    if (!el.classList.contains('boarding-plugin')) {
                        el.classList.add('boarding-plugin');
                    }
                });
            }, 50);

            localStorage.setItem('boarding-current-tour', JSON.stringify({
                tourId: tourData.id || tourData.tourId,
                currentStep: 0,
            }));
        });

        tour.on('show', evt => {
            document.querySelectorAll('.shepherd-element').forEach(el => {
                if (!el.classList.contains('boarding-plugin')) {
                    el.classList.add('boarding-plugin');
                }
            });

            const currentStepIndex = tour.steps.indexOf(evt.step);
            localStorage.setItem('boarding-current-tour', JSON.stringify({
                tourId: tourData.id || tourData.tourId,
                currentStep: currentStepIndex,
            }));
        });

        tour.on('complete', () => {
            localStorage.removeItem('boarding-current-tour');

            const tourId = tourData.tourId || tourData.id;
            if (tourId) {
                this.markTourCompleted(tourId);
            }

            if (Craft.cp) {
                Craft.cp.displayNotice('Tour completed successfully!');
            }
        });

        tour.on('cancel', () => {
            localStorage.removeItem('boarding-current-tour');
        });
    }

    /**
     * Set up progress indicator
     */
    setupProgressIndicator(tour, tourData) {
        const progressPosition = tourData.progressPosition;

        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'childList' && mutation.addedNodes.length) {
                    mutation.addedNodes.forEach(node => {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            const stepElements = node.classList && node.classList.contains('shepherd-element')
                                ? [node]
                                : node.querySelectorAll('.shepherd-element');

                            if (stepElements.length) {
                                stepElements.forEach(stepElement => {
                                    const currentStep = tour.steps.find(step => step.getElement() === stepElement);
                                    if (!currentStep) return;

                                    const currentStepIndex = tour.steps.indexOf(currentStep);
                                    const totalSteps = tour.steps.length;
                                    const progressText = `${currentStepIndex + 1} of ${totalSteps}`;

                                    if (progressPosition === 'header') {
                                        this.addProgressToHeader(stepElement, progressText);
                                    } else if (progressPosition === 'footer') {
                                        this.addProgressToFooter(stepElement, progressText);
                                    }
                                });
                            }
                        }
                    });
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });

        tour.on('complete', () => observer.disconnect());
        tour.on('cancel', () => observer.disconnect());
    }

    /**
     * Add progress indicator to header
     */
    addProgressToHeader(stepElement, progressText) {
        setTimeout(() => {
            const header = stepElement.querySelector('.shepherd-header');
            if (header && !header.querySelector('.boarding-progress-indicator')) {
                const progress = document.createElement('span');
                progress.className = 'boarding-progress-indicator boarding-header-progress boarding-plugin';
                progress.textContent = progressText;

                const cancelIcon = header.querySelector('.shepherd-cancel-icon');
                if (cancelIcon) {
                    header.insertBefore(progress, cancelIcon);
                } else {
                    header.appendChild(progress);
                }
            }
        }, 10);
    }

    /**
     * Add progress indicator to footer
     */
    addProgressToFooter(stepElement, progressText) {
        setTimeout(() => {
            const footer = stepElement.querySelector('.shepherd-footer');
            if (footer && !footer.querySelector('.boarding-progress-indicator')) {
                const buttonsWrapper = footer.querySelector('.shepherd-buttons');
                const progress = document.createElement('div');
                progress.className = 'boarding-progress-indicator boarding-footer-progress boarding-plugin';
                progress.textContent = progressText;

                if (buttonsWrapper) {
                    footer.insertBefore(progress, buttonsWrapper);
                } else {
                    footer.appendChild(progress);
                }
            }
        }, 10);
    }

    /**
     * Add steps to tour
     */
    addStepsToTour(tour, tourData) {
        const currentSiteId = this.getCurrentSiteId();
        const isTranslatable = tourData.propagationMethod && tourData.propagationMethod !== 'none';

        tourData.steps.forEach((stepData, index) => {
            if (!stepData || typeof stepData !== 'object') {
                return;
            }

            const step = this.buildStep(stepData, index, tourData, currentSiteId, isTranslatable);
            tour.addStep(step);
        });
    }

    /**
     * Build a single step
     */
    buildStep(stepData, index, tourData, currentSiteId, isTranslatable) {
        let title = stepData.title || '';
        let text = stepData.text || stepData.content || '';
        let navigationButtonText = stepData.navigationButtonText || 'Continue';

        // Apply translations if available
        if (isTranslatable && currentSiteId && stepData.translations && stepData.translations[currentSiteId]) {
            const translation = stepData.translations[currentSiteId];
            if (translation.title && translation.title.trim() !== '') {
                title = translation.title;
            }
            if (translation.text && translation.text.trim() !== '') {
                text = translation.text;
            }
            if (stepData.type === 'navigation' && translation.navigationButtonText && translation.navigationButtonText.trim() !== '') {
                navigationButtonText = translation.navigationButtonText;
            }
        }

        const step = {
            id: `step-${index}`,
            title: title,
            text: text,
            buttons: [],
        };

        // Add attachment if specified
        if (stepData.attachTo && stepData.attachTo.element) {
            const element = document.querySelector(stepData.attachTo.element);
            if (element) {
                step.attachTo = {
                    element: stepData.attachTo.element,
                    on: stepData.attachTo.on || stepData.position || 'bottom',
                };
            }
        } else if (stepData.target) {
            const element = document.querySelector(stepData.target);
            if (element) {
                step.attachTo = {
                    element: stepData.target,
                    on: stepData.position || 'bottom',
                };
            }
        }

        // Add buttons
        if (stepData.type === 'navigation') {
            step.buttons = this.buildNavigationButtons(index, tourData, stepData, navigationButtonText);
        } else if (stepData.buttons && stepData.buttons.length) {
            step.buttons = this.buildCustomButtons(stepData.buttons);
        } else {
            step.buttons = this.buildDefaultButtons(index, tourData.steps.length);
        }

        return step;
    }

    /**
     * Build navigation step buttons
     */
    buildNavigationButtons(index, tourData, stepData, navigationButtonText) {
        const buttons = [];

        if (index > 0) {
            buttons.push({
                text: this.settings.backButtonText,
                classes: 'shepherd-button-secondary',
                action() { return this.back(); },
            });
        }

        buttons.push({
            text: navigationButtonText,
            classes: 'shepherd-button-primary',
            action: function () {
                const isLastStep = index === tourData.steps.length - 1;
                if (isLastStep) {
                    this.complete();
                } else {
                    localStorage.setItem('boarding-current-tour', JSON.stringify({
                        tourId: tourData.tourId || tourData.id,
                        nextStep: index + 1,
                    }));
                }
                window.location.href = stepData.navigationUrl;
            },
        });

        return buttons;
    }

    /**
     * Build custom buttons
     */
    buildCustomButtons(buttonsData) {
        return buttonsData.map(buttonData => {
            const button = {
                text: buttonData.text,
                classes: buttonData.classes || '',
            };

            switch (buttonData.action) {
                case 'next':
                    button.action = function () { return this.next(); };
                    break;
                case 'back':
                    button.action = function () { return this.back(); };
                    break;
                case 'complete':
                    button.action = function () { this.complete(); };
                    break;
                default:
                    button.action = function () { return this.next(); };
            }

            return button;
        });
    }

    /**
     * Build default buttons
     */
    buildDefaultButtons(index, totalSteps) {
        const buttons = [];

        if (index > 0) {
            buttons.push({
                text: this.settings.backButtonText,
                classes: 'shepherd-button-secondary',
                action() { return this.back(); },
            });
        }

        if (index === totalSteps - 1) {
            buttons.push({
                text: this.settings.doneButtonText,
                classes: 'shepherd-button-primary',
                action() { this.complete(); },
            });
        } else {
            buttons.push({
                text: this.settings.nextButtonText,
                classes: 'shepherd-button-primary',
                action() { return this.next(); },
            });
        }

        return buttons;
    }

    /**
     * Get current site ID
     */
    getCurrentSiteId() {
        if (Craft && Craft.siteId) {
            return parseInt(Craft.siteId, 10);
        } else if (Craft && Craft.currentSite && Craft.currentSite.id) {
            return parseInt(Craft.currentSite.id, 10);
        } else {
            const bodySiteId = document.body.getAttribute('data-site');
            if (bodySiteId) {
                return parseInt(bodySiteId, 10);
            }
        }
        return null;
    }

    /**
     * Mark tour as completed
     */
    async markTourCompleted(tourId) {
        if (!tourId) return;

        const formData = new FormData();
        formData.append('tourId', tourId);

        const url = Craft.getActionUrl('boarding/tours/mark-completed');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': Craft.csrfTokenValue,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                // Update cached tours
                const tourIndex = this.cachedTours.findIndex(t => t.tourId === tourId || t.id === tourId);
                if (tourIndex !== -1) {
                    this.cachedTours[tourIndex].completed = true;
                }

                // Refresh tours from server
                await this.reloadTours();
            }
        } catch (error) {
            if (Craft.cp) {
                Craft.cp.displayError('Error marking tour as completed: ' + error.message);
            }
        }
    }

    /**
     * Load and start a tour by ID
     */
    async loadTourById(tourId, forceReload = false) {
        if (!tourId) {
            throw new Error('Invalid tour ID');
        }

        if (!forceReload && this.cachedTours.length > 0) {
            const tour = this.findTourById(this.cachedTours, tourId);
            if (tour) {
                return this.startTour(tour);
            }
        }

        const tours = await this.reloadTours();
        const tour = this.findTourById(tours, tourId);

        if (tour) {
            return this.startTour(tour);
        } else {
            throw new Error('Tour not found: ' + tourId);
        }
    }

    /**
     * Add tours button to UI
     */
    addToursButton(tours) {
        try {
            this.removeExistingButton();
            const button = this.createButton();

            if (this.settings.buttonPosition === 'header') {
                this.addButtonToHeader(button);
            } else if (this.settings.buttonPosition === 'sidebar') {
                this.addButtonToSidebar(button);
            }

            button.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                this.showToursMenu(tours);
            });
        } catch (error) {
            console.error('Boarding: Error adding tours button', error);
        }
    }

    /**
     * Remove existing button
     */
    removeExistingButton() {
        const existingButton = document.querySelector('.boarding-tours-button');
        if (existingButton) {
            existingButton.remove();
        }
    }

    /**
     * Create button element
     */
    createButton() {
        const button = document.createElement('div');
        button.className = 'boarding-tours-button';
        return button;
    }

    /**
     * Add button to header
     */
    addButtonToHeader(button) {
        const header = document.querySelector('#global-header .flex');
        if (header) {
            button.innerHTML = `<button class="btn">${this.settings.buttonLabel}</button>`;
            header.appendChild(button);
        }
    }

    /**
     * Add button to sidebar
     */
    addButtonToSidebar(button) {
        const sidebar = document.querySelector('#global-sidebar nav');
        if (sidebar) {
            button.innerHTML = `
                <div class="nav-item">
                    <a class="sidebar-action" href="#">
                        <span class="icon">
                            <svg width="24" height="24" viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
                            </svg>
                        </span>
                        <span class="label">${this.settings.buttonLabel}</span>
                    </a>
                </div>
            `;
            sidebar.appendChild(button);
        }
    }

    /**
     * Show tours menu
     */
    showToursMenu(tours) {
        console.log('Boarding: showToursMenu called with tours:', tours);

        if (this.currentMenu) {
            this.currentMenu.remove();
            this.currentMenu = null;
            return;
        }

        const menu = document.createElement('div');
        menu.className = 'boarding-tours-menu boarding-plugin';
        this.currentMenu = menu;

        let menuHtml = '<div class="boarding-tours-menu-items">';

        if (!tours || tours.length === 0) {
            console.log('Boarding: No tours to display');
            menuHtml += '<div class="boarding-tour-item"><p>No tours available</p></div>';
        } else {
            console.log('Boarding: Displaying', tours.length, 'tours');
            tours.forEach(tour => {
                const tourId = tour.tourId || tour.id;
                const isCompleted = this.isCompletedTour(tour);
                const completedIcon = isCompleted ? '<span class="completed-icon" title="Tour completed">✓</span>' : '';
                const tourStatus = isCompleted ? 'completed' : '';
                const buttonText = isCompleted ? 'Restart Tour' : 'Start Tour';

                menuHtml += `
                    <div class="boarding-tour-item ${tourStatus}" data-tour-id="${tourId}">
                        <h3>${tour.name} ${completedIcon}</h3>
                        <p>${tour.description || ''}</p>
                        <button class="main-tour-btn btn" data-action="start">${buttonText}</button>
                    </div>
                `;
            });
        }

        menuHtml += '</div>';
        menu.innerHTML = menuHtml;

        // Add click handlers
        menu.querySelectorAll('.boarding-tour-item .main-tour-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const tourId = btn.closest('.boarding-tour-item').dataset.tourId;
                const tour = this.findTourById(tours, tourId);

                if (tour) {
                    this.startTour(tour);
                    menu.remove();
                    this.currentMenu = null;
                } else if (Craft.cp) {
                    Craft.cp.displayError('Could not find the requested tour.');
                    menu.remove();
                    this.currentMenu = null;
                }
            });
        });

        // Add close button
        const closeBtn = document.createElement('button');
        closeBtn.className = 'boarding-tours-menu-close';
        closeBtn.innerHTML = '×';
        closeBtn.addEventListener('click', () => {
            menu.remove();
            this.currentMenu = null;
        });
        menu.appendChild(closeBtn);

        document.body.appendChild(menu);

        // Close menu on outside click
        const closeMenu = e => {
            if (this.currentMenu && !this.currentMenu.contains(e.target)) {
                const tourButton = document.querySelector('.boarding-tours-button');
                if (!tourButton || !tourButton.contains(e.target)) {
                    this.currentMenu.remove();
                    this.currentMenu = null;
                    document.removeEventListener('click', closeMenu);
                }
            }
        };

        document.addEventListener('click', closeMenu);
    }

    /**
     * Check if tour is completed
     */
    isCompletedTour(tour) {
        if (tour.completedBy && tour.completedBy.length) {
            const currentUserEmail = Craft.username;
            return tour.completedBy.some(completion => completion.username === currentUserEmail);
        }
        return false;
    }

    /**
     * Find tour by ID
     */
    findTourById(tours, tourId) {
        if (!tours || !Array.isArray(tours) || tours.length === 0 || !tourId) {
            return null;
        }

        const normalizedSearchId = this.normalizeId(tourId);

        return tours.find(t => {
            const tourIdNormalized = this.normalizeId(t.tourId);
            const idNormalized = this.normalizeId(t.id);
            return tourIdNormalized === normalizedSearchId || idNormalized === normalizedSearchId;
        });
    }

    /**
     * Normalize ID for comparison
     */
    normalizeId(id) {
        if (id === null || id === undefined) {
            return '';
        }

        const idStr = String(id).trim();

        // For UUIDs, ensure consistent format (lowercase, no dashes)
        const uuidPattern = /^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i;
        if (uuidPattern.test(idStr)) {
            return idStr.toLowerCase().replace(/-/g, '');
        }

        return idStr;
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.Boarding = new BoardingManager(window.boardingSettings || {});
        window.Boarding.init();
    });
} else {
    window.Boarding = new BoardingManager(window.boardingSettings || {});
    window.Boarding.init();
}
