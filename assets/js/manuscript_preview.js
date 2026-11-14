import * as pdfjsLib from '../vendor/pdfjs/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;

const viewer = document.getElementById('manuscript-viewer');
const pageCanvas = document.getElementById('manuscript-page');
const canvasWrap = document.getElementById('manuscript-page-canvas-wrap');
const pageInput = document.getElementById('page-number');
const pageCountLabel = document.getElementById('page-count');
const previousButton = document.getElementById('previous-page');
const nextButton = document.getElementById('next-page');
const fullscreenButton = document.getElementById('fullscreen-preview');
const stage = document.getElementById('manuscript-page-stage');
const errorMessage = document.getElementById('manuscript-preview-error');
const commentPageInput = document.getElementById('comment-page');

if (!(viewer instanceof HTMLElement)
    || !(pageCanvas instanceof HTMLCanvasElement)
    || !(canvasWrap instanceof HTMLElement)
    || !(pageInput instanceof HTMLInputElement)
    || !(pageCountLabel instanceof HTMLElement)
    || !(previousButton instanceof HTMLButtonElement)
    || !(nextButton instanceof HTMLButtonElement)
    || !(fullscreenButton instanceof HTMLButtonElement)
    || !(stage instanceof HTMLElement)
    || !(errorMessage instanceof HTMLElement)) {
    throw new Error('The manuscript preview could not be initialized.');
}

let pdfDocument = null;
let currentPage = 1;
let renderTask = null;
let renderSequence = 0;
let resizeObserver = null;

function updateNavigation() {
    pageInput.value = String(currentPage);
    pageInput.max = String(pdfDocument.numPages);
    pageInput.disabled = false;
    pageCountLabel.textContent = `of ${pdfDocument.numPages} pages`;
    previousButton.disabled = currentPage <= 1;
    nextButton.disabled = currentPage >= pdfDocument.numPages;

    if (commentPageInput instanceof HTMLInputElement) {
        commentPageInput.min = '1';
        commentPageInput.max = String(pdfDocument.numPages);
        commentPageInput.value = String(currentPage);
        commentPageInput.disabled = false;
    }
}

async function renderCurrentPage() {
    if (!pdfDocument) return;

    const sequence = ++renderSequence;
    if (renderTask) {
        renderTask.cancel();
        renderTask = null;
    }

    let task = null;
    try {
        const page = await pdfDocument.getPage(currentPage);
        if (sequence !== renderSequence) return;

        const baseViewport = page.getViewport({ scale: 1 });
        const availableWidth = Math.max(1, stage.clientWidth - 32);
        const availableHeight = Math.max(1, stage.clientHeight - 32);
        const scale = Math.min(
            availableWidth / baseViewport.width,
            availableHeight / baseViewport.height,
            2
        );
        const viewport = page.getViewport({ scale });
        const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
        const renderCanvas = document.createElement('canvas');
        const canvasContext = renderCanvas.getContext('2d');
        if (!canvasContext) {
            throw new Error('The browser could not create the PDF preview canvas.');
        }

        renderCanvas.width = Math.ceil(viewport.width * pixelRatio);
        renderCanvas.height = Math.ceil(viewport.height * pixelRatio);

        task = page.render({
            canvasContext,
            viewport,
            transform: pixelRatio === 1 ? null : [pixelRatio, 0, 0, pixelRatio, 0, 0],
        });
        renderTask = task;
        await task.promise;
        if (sequence === renderSequence) {
            pageCanvas.width = renderCanvas.width;
            pageCanvas.height = renderCanvas.height;
            const pageCanvasContext = pageCanvas.getContext('2d');
            if (!pageCanvasContext) {
                throw new Error('The browser could not update the PDF preview canvas.');
            }
            pageCanvas.style.width = `${viewport.width}px`;
            pageCanvas.style.height = `${viewport.height}px`;
            canvasWrap.style.width = `${viewport.width}px`;
            canvasWrap.style.height = `${viewport.height}px`;
            pageCanvas.setAttribute('aria-label', `Manuscript page ${currentPage} of ${pdfDocument.numPages}`);
            pageCanvasContext.drawImage(renderCanvas, 0, 0);
            errorMessage.hidden = true;
        }
        if (renderTask === task) renderTask = null;
    } catch (error) {
        if (renderTask === task) renderTask = null;
        if (error?.name === 'RenderingCancelledException' || sequence !== renderSequence) return;
        errorMessage.textContent = error instanceof Error
            ? error.message
            : 'The manuscript page could not be rendered.';
        errorMessage.hidden = false;
        console.error('Manuscript PDF preview failed:', error);
    }
}

async function showPage(requestedPage) {
    const page = Number.parseInt(String(requestedPage), 10);
    if (!pdfDocument || !Number.isInteger(page)) return;

    currentPage = Math.min(Math.max(page, 1), pdfDocument.numPages);
    updateNavigation();
    await renderCurrentPage();
}

pageInput.addEventListener('change', () => void showPage(pageInput.value));
pageInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        void showPage(pageInput.value);
    }
});
previousButton.addEventListener('click', () => void showPage(currentPage - 1));
nextButton.addEventListener('click', () => void showPage(currentPage + 1));

if (commentPageInput instanceof HTMLInputElement) {
    commentPageInput.disabled = true;
    commentPageInput.addEventListener('change', () => void showPage(commentPageInput.value));
}

document.querySelectorAll('.manuscript-comment-page').forEach((button) => {
    button.addEventListener('click', () => void showPage(button.dataset.page));
});

fullscreenButton.addEventListener('click', async () => {
    try {
        if (document.fullscreenElement === viewer) {
            await document.exitFullscreen();
        } else if (viewer.requestFullscreen) {
            await viewer.requestFullscreen();
        } else {
            throw new Error('Full-screen preview is not supported by this browser.');
        }
    } catch (error) {
        errorMessage.textContent = error instanceof Error ? error.message : 'Could not open the preview in full screen.';
        errorMessage.hidden = false;
    }
});

document.addEventListener('fullscreenchange', () => {
    const isFullscreen = document.fullscreenElement === viewer;
    fullscreenButton.textContent = isFullscreen ? 'Exit full screen' : 'Full screen';
    fullscreenButton.setAttribute('aria-pressed', String(isFullscreen));
    void renderCurrentPage();
});

resizeObserver = new ResizeObserver(() => {
    if (pdfDocument) void renderCurrentPage();
});
resizeObserver.observe(stage);

try {
    const pdfResponse = await fetch(viewer.dataset.pdfUrl, { credentials: 'same-origin' });
    if (!pdfResponse.ok) {
        throw new Error(`The manuscript PDF request failed with status ${pdfResponse.status}.`);
    }

    const loadingTask = pdfjsLib.getDocument({
        data: new Uint8Array(await pdfResponse.arrayBuffer()),
        cMapUrl: new URL('../vendor/pdfjs/cmaps/', import.meta.url).href,
        cMapPacked: true,
        iccUrl: new URL('../vendor/pdfjs/iccs/', import.meta.url).href,
        standardFontDataUrl: new URL('../vendor/pdfjs/standard_fonts/', import.meta.url).href,
        wasmUrl: new URL('../vendor/pdfjs/wasm/', import.meta.url).href,
        isEvalSupported: false,
    });
    pdfDocument = await loadingTask.promise;
    if (pdfDocument.numPages < 1) {
        throw new Error('The manuscript PDF does not contain any pages.');
    }
    currentPage = 1;
    updateNavigation();
    await renderCurrentPage();
} catch (error) {
    pageInput.disabled = true;
    previousButton.disabled = true;
    nextButton.disabled = true;
    if (commentPageInput instanceof HTMLInputElement) commentPageInput.disabled = true;
    pageCountLabel.textContent = 'Pages unavailable';
    errorMessage.textContent = 'The manuscript could not be loaded. Reload the page or contact an administrator.';
    errorMessage.hidden = false;
    console.error('Manuscript PDF could not be loaded:', error);
}
