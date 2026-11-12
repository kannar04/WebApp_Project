/*
 * Home2Home slippy map: OpenStreetMap raster tiles with Web-Mercator math, no external library.
 * Tiles are images limited to the visible viewport (OSM tile policy: no prefetching).
 * Modes: data-discovery-map="search" (map search page), "static" (listing detail), "picker" (Host form).
 */
(function () {
    'use strict';

    const TILE_SIZE = 256;
    const MIN_ZOOM = 3;
    const MAX_ZOOM = 18;
    const PLACE_NAME_ZOOM = 17;
    const MAX_LATITUDE = 85.0511287798;
    const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const FALLBACK_TILE_URL = 'https://a.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png';
    const VIETNAM = {lat: 16.0, lng: 106.4, zoom: 5};
    const PAN_STEP = 80;
    const QUERY_DELAY = 300;
    // Searched places without any mapped result still open on the right region.
    const KNOWN_PLACES = [
        ['ha noi', 21.0285, 105.8542], ['ho chi minh', 10.7769, 106.7009], ['sai gon', 10.7769, 106.7009],
        ['da nang', 16.0544, 108.2022], ['hoi an', 15.8801, 108.3380], ['da lat', 11.9404, 108.4583],
        ['nha trang', 12.2388, 109.1967], ['hue', 16.4637, 107.5909], ['phu quoc', 10.2899, 103.9840],
        ['sa pa', 22.3364, 103.8438], ['sapa', 22.3364, 103.8438], ['vung tau', 10.3460, 107.0843],
        ['ha long', 20.9599, 107.0425], ['quy nhon', 13.7820, 109.2197], ['can tho', 10.0452, 105.7469],
        ['hai phong', 20.8449, 106.6881], ['ninh binh', 20.2506, 105.9745], ['phan thiet', 10.9289, 108.1021],
        ['mui ne', 10.9333, 108.2833], ['con dao', 8.6820, 106.6090]
    ];
    let instanceCount = 0;

    function clamp(value, min, max) { return Math.min(max, Math.max(min, value)); }
    function worldSize(zoom) { return TILE_SIZE * Math.pow(2, zoom); }

    function project(lat, lng, zoom) {
        const size = worldSize(zoom);
        const sin = Math.sin(clamp(lat, -MAX_LATITUDE, MAX_LATITUDE) * Math.PI / 180);
        return {x: (lng + 180) / 360 * size, y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * size};
    }

    function unproject(x, y, zoom) {
        const size = worldSize(zoom);
        const n = Math.PI - 2 * Math.PI * y / size;
        return {lat: 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))), lng: x / size * 360 - 180};
    }

    function wrapLongitude(lng) { return ((lng + 180) % 360 + 360) % 360 - 180; }

    function plainText(text) {
        return String(text || '').toLocaleLowerCase('vi').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').trim();
    }

    function knownPlace(text) {
        const needle = plainText(text);
        if (needle.length < 2) { return null; }
        for (const place of KNOWN_PLACES) {
            if (needle.indexOf(place[0]) !== -1) { return {lat: place[1], lng: place[2]}; }
        }
        return null;
    }

    function compactPrice(price) {
        // Number format stays Vietnamese; only the million abbreviation ("tr") is a word, so it goes through h2hT.
        if (price >= 1000000) { return window.h2hT('{amount}tr', {amount: (Math.round(price / 100000) / 10).toLocaleString('vi-VN')}); }
        if (price >= 1000) { return Math.round(price / 1000).toLocaleString('vi-VN') + 'k'; }
        // Money keeps the Vietnamese format (dong sign) in both languages.
        return Math.round(price).toLocaleString('vi-VN') + '\u0111';
    }

    function parseJson(text, fallback) {
        try { return JSON.parse(text || ''); } catch (error) { return fallback; }
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    class SlippyMap {
        constructor(container, options) {
            this.container = container;
            this.options = options;
            this.interactive = options.interactive !== false;
            this.zoom = clamp(Math.round(options.zoom || VIETNAM.zoom), MIN_ZOOM, MAX_ZOOM);
            this.center = {lat: options.center.lat, lng: options.center.lng};
            this.tiles = new Map();
            this.tileUrl = TILE_URL;
            this.markers = [];
            this.pin = null;
            this.openMarker = null;
            this.drag = null;
            this.lastWheel = 0;
            this.id = 'h2h-map-' + (++instanceCount);
            this.build();
            this.render();
        }

        build() {
            const viewport = element('div', 'h2h-map-viewport' + (this.interactive ? '' : ' is-static'));
            viewport.setAttribute('role', 'region');
            viewport.setAttribute('aria-roledescription', window.h2hT('bản đồ'));
            viewport.setAttribute('aria-label', (this.options.label || window.h2hT('Bản đồ')) + (this.interactive
                ? window.h2hT('. Dùng phím mũi tên để di chuyển, phím + và − để phóng to hoặc thu nhỏ.') : ''));
            if (this.interactive) { viewport.tabIndex = 0; }
            this.tilePane = element('div', 'h2h-map-tiles');
            this.tilePane.setAttribute('aria-hidden', 'true');
            this.markerPane = element('div', 'h2h-map-markers');
            this.popup = element('div', 'h2h-map-popup');
            this.popup.id = this.id + '-popup';
            this.popup.setAttribute('role', 'dialog');
            this.popup.hidden = true;
            viewport.append(this.tilePane, this.markerPane, this.popup);
            if (this.options.onCenterSelect) {
                const crosshair = element('div', 'h2h-map-crosshair');
                crosshair.setAttribute('aria-hidden', 'true');
                viewport.append(crosshair);
            }
            const controls = element('div', 'h2h-map-controls');
            this.zoomInButton = element('button', '', '+');
            this.zoomOutButton = element('button', '', '−');
            this.zoomInButton.type = 'button';
            this.zoomOutButton.type = 'button';
            this.zoomInButton.setAttribute('aria-label', window.h2hT('Phóng to bản đồ'));
            this.zoomOutButton.setAttribute('aria-label', window.h2hT('Thu nhỏ bản đồ'));
            controls.append(this.zoomInButton, this.zoomOutButton);
            const attribution = element('div', 'h2h-map-attribution', '© ');
            const link = element('a', '', 'OpenStreetMap contributors');
            link.href = 'https://www.openstreetmap.org/copyright';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            attribution.append(link);
            this.fallbackCredit = element('span');
            this.fallbackCredit.hidden = true;
            for (const [name, href] of [['HOT', 'https://www.hotosm.org/'], ['OSM France', 'https://www.openstreetmap.fr/']]) {
                const credit = element('a', '', name);
                credit.href = href;
                credit.target = '_blank';
                credit.rel = 'noopener noreferrer';
                this.fallbackCredit.append(' · ', credit);
            }
            attribution.append(this.fallbackCredit);
            this.tileError = element('div', 'h2h-map-error');
            this.tileError.hidden = true;
            this.tileError.setAttribute('role', 'status');
            const retry = element('button', 'btn btn-sm btn-outline-primary', window.h2hT('Thử lại'));
            retry.type = 'button';
            retry.addEventListener('click', () => {
                this.tileError.hidden = true;
                this.tilePane.replaceChildren();
                this.tiles.clear();
                this.render();
            });
            this.tileError.append(element('span', '', window.h2hT('Không tải được nền bản đồ. Kiểm tra kết nối mạng rồi thử lại.')), retry);
            this.container.classList.add('h2h-map');
            this.container.append(viewport, controls, attribution, this.tileError);
            this.viewport = viewport;

            this.zoomInButton.addEventListener('click', this);
            this.zoomOutButton.addEventListener('click', this);
            this.markerPane.addEventListener('click', this);
            this.popup.addEventListener('click', this);
            viewport.addEventListener('keydown', this);
            viewport.addEventListener('scroll', this);
            viewport.addEventListener('pointerdown', this);
            viewport.addEventListener('pointermove', this);
            viewport.addEventListener('pointerup', this);
            viewport.addEventListener('pointercancel', this);
            if (this.interactive) {
                viewport.addEventListener('wheel', this, {passive: false});
                viewport.addEventListener('dblclick', this);
            }
            window.addEventListener('resize', this);
        }

        handleEvent(event) {
            switch (event.type) {
                case 'click': this.onClick(event); break;
                case 'keydown': this.onKeyDown(event); break;
                case 'scroll': this.viewport.scrollTop = 0; this.viewport.scrollLeft = 0; break;
                case 'pointerdown': this.onPointerDown(event); break;
                case 'pointermove': this.onPointerMove(event); break;
                case 'pointerup': this.onPointerUp(event, false); break;
                case 'pointercancel': this.onPointerUp(event, true); break;
                case 'wheel': this.onWheel(event); break;
                case 'dblclick': this.onDoubleClick(event); break;
                case 'resize': this.render(); break;
            }
        }

        size() { return {width: this.viewport.clientWidth, height: this.viewport.clientHeight}; }

        origin() {
            const size = this.size();
            const center = project(this.center.lat, this.center.lng, this.zoom);
            return {x: center.x - size.width / 2, y: center.y - size.height / 2, centerX: center.x, width: size.width, height: size.height};
        }

        setCenterWorld(x, y) {
            const size = worldSize(this.zoom);
            const height = this.viewport.clientHeight;
            const clampedY = size > height ? clamp(y, height / 2, size - height / 2) : size / 2;
            const point = unproject(((x % size) + size) % size, clampedY, this.zoom);
            this.center = {lat: point.lat, lng: point.lng};
        }

        setView(lat, lng, zoom) {
            if (zoom !== undefined) { this.setZoomLevel(zoom); }
            const point = project(lat, wrapLongitude(lng), this.zoom);
            this.setCenterWorld(point.x, point.y);
            this.render();
        }

        setZoomLevel(zoom) {
            const next = clamp(Math.round(zoom), MIN_ZOOM, MAX_ZOOM);
            if (next !== this.zoom) {
                this.zoom = next;
                this.tilePane.replaceChildren();
                this.tiles.clear();
            }
        }

        /** Largest zoom (up to maxZoom) that shows every point with padding. */
        fitPoints(points, maxZoom) {
            const size = this.size();
            if (!points.length || !size.width || !size.height) { return false; }
            let south = 90; let north = -90; let west = 180; let east = -180;
            for (const point of points) {
                south = Math.min(south, point.latitude); north = Math.max(north, point.latitude);
                west = Math.min(west, point.longitude); east = Math.max(east, point.longitude);
            }
            const padding = Math.min(64, size.width / 6, size.height / 6);
            let zoom = clamp(maxZoom || 13, MIN_ZOOM, MAX_ZOOM);
            for (; zoom > MIN_ZOOM; zoom--) {
                const topLeft = project(north, west, zoom);
                const bottomRight = project(south, east, zoom);
                if (bottomRight.x - topLeft.x <= size.width - 2 * padding && bottomRight.y - topLeft.y <= size.height - 2 * padding) { break; }
            }
            this.setZoomLevel(zoom);
            const a = project(north, west, this.zoom);
            const b = project(south, east, this.zoom);
            this.setCenterWorld((a.x + b.x) / 2, (a.y + b.y) / 2);
            this.render();
            return true;
        }

        /** Visible box; west greater than east means the box crosses the antimeridian. */
        getBounds() {
            const view = this.origin();
            const size = worldSize(this.zoom);
            const top = unproject(view.x, Math.max(0, view.y), this.zoom);
            const bottom = unproject(view.x, Math.min(size, view.y + view.height), this.zoom);
            let west = -180;
            let east = 180;
            if (view.width < size) {
                const span = view.width / size * 360;
                west = wrapLongitude(top.lng);
                east = west + span;
                if (east > 180) { east -= 360; }
            }
            return {north: clamp(top.lat, -90, 90), south: clamp(bottom.lat, -90, 90), east: east, west: west};
        }

        panBy(dx, dy) {
            const center = project(this.center.lat, this.center.lng, this.zoom);
            this.setCenterWorld(center.x + dx, center.y + dy);
            this.render();
        }

        zoomAround(zoom, anchorX, anchorY) {
            const next = clamp(Math.round(zoom), MIN_ZOOM, MAX_ZOOM);
            if (next === this.zoom) { return false; }
            const view = this.origin();
            const x = anchorX === undefined ? view.width / 2 : anchorX;
            const y = anchorY === undefined ? view.height / 2 : anchorY;
            const scale = Math.pow(2, next - this.zoom);
            const worldX = (view.x + x) * scale;
            const worldY = (view.y + y) * scale;
            this.setZoomLevel(next);
            this.setCenterWorld(worldX - x + view.width / 2, worldY - y + view.height / 2);
            this.render();
            return true;
        }

        render() {
            this.container.classList.toggle('is-place-names', this.zoom >= PLACE_NAME_ZOOM);
            const view = this.origin();
            if (!view.width || !view.height) { return; }
            const count = Math.pow(2, this.zoom);
            const firstX = Math.floor(view.x / TILE_SIZE);
            const lastX = Math.floor((view.x + view.width - 1) / TILE_SIZE);
            const firstY = Math.max(0, Math.floor(view.y / TILE_SIZE));
            const lastY = Math.min(count - 1, Math.floor((view.y + view.height - 1) / TILE_SIZE));
            const visible = new Set();
            for (let tileX = firstX; tileX <= lastX; tileX++) {
                for (let tileY = firstY; tileY <= lastY; tileY++) {
                    const key = tileX + '/' + tileY;
                    let tile = this.tiles.get(key);
                    if (!tile) {
                        tile = element('img', 'h2h-map-tile');
                        tile.alt = '';
                        tile.draggable = false;
                        tile.width = TILE_SIZE;
                        tile.height = TILE_SIZE;
                        const wrappedX = ((tileX % count) + count) % count;
                        tile.dataset.path = this.zoom + '/' + wrappedX + '/' + tileY + '.png';
                        tile.addEventListener('error', () => this.onTileError(tile));
                        tile.addEventListener('load', () => {
                            if (this.tilePane.contains(tile) && !this.tilePane.querySelector('[data-tile-failed]')) { this.tileError.hidden = true; }
                        });
                        tile.src = this.tileUrl.replace('{z}/{x}/{y}.png', tile.dataset.path);
                        this.tilePane.append(tile);
                        this.tiles.set(key, tile);
                    }
                    tile.style.transform = 'translate(' + Math.round(tileX * TILE_SIZE - view.x) + 'px,' + Math.round(tileY * TILE_SIZE - view.y) + 'px)';
                    visible.add(key);
                }
            }
            for (const entry of this.tiles) {
                if (!visible.has(entry[0])) { entry[1].remove(); this.tiles.delete(entry[0]); }
            }
            for (const marker of this.markers) { this.place(marker, view); }
            if (this.pin) { this.place(this.pin, view); }
            this.positionPopup(view);
            this.zoomInButton.setAttribute('aria-disabled', String(this.zoom >= MAX_ZOOM));
            this.zoomOutButton.setAttribute('aria-disabled', String(this.zoom <= MIN_ZOOM));
        }

        onTileError(tile) {
            if (!this.tilePane.contains(tile)) { return; }
            // A late error from the original source must not mark a replacement tile as failed.
            if (this.tileUrl === FALLBACK_TILE_URL && tile.src.startsWith('https://tile.openstreetmap.org/')) { return; }
            if (this.tileUrl === TILE_URL) {
                this.tileUrl = FALLBACK_TILE_URL;
                this.fallbackCredit.hidden = false;
                this.tilePane.replaceChildren();
                this.tiles.clear();
                this.render();
            } else {
                tile.dataset.tileFailed = 'true';
                this.tileError.hidden = false;
            }
        }

        place(marker, view) {
            const size = worldSize(this.zoom);
            const point = project(marker.lat, marker.lng, this.zoom);
            const x = point.x + Math.round((view.centerX - point.x) / size) * size - view.x;
            const y = point.y - view.y;
            marker.x = x;
            marker.y = y;
            marker.node.hidden = x < -40 || y < -10 || x > view.width + 40 || y > view.height + 60;
            marker.node.style.transform = 'translate(' + Math.round(x) + 'px,' + Math.round(y) + 'px) translate(-50%,-100%)' + (marker.rotate || '');
        }

        setMarkers(items) {
            this.closePopup(false);
            this.markerPane.replaceChildren();
            if (this.pin) { this.markerPane.append(this.pin.node); }
            this.markers = [];
            for (const item of items) {
                const node = element('button', 'h2h-map-marker' + (item.marker_label ? ' h2h-map-marker-place' : ''), item.service ? (item.marker_label || item.category_label) : compactPrice(item.price));
                node.type = 'button';
                node.dataset.listingId = String(item.id);
                node.setAttribute('aria-label', item.service ? item.title + ', ' + item.category_label : window.h2hT('{title}, {price} mỗi đêm', {title: item.title, price: item.price_label}));
                node.setAttribute('aria-expanded', 'false');
                node.setAttribute('aria-controls', this.popup.id);
                this.markerPane.append(node);
                if (item.marker_label) {
                    const number = element('span', 'h2h-map-place-number', item.marker_label);
                    const name = element('span', 'h2h-map-place-name', item.title);
                    number.setAttribute('aria-hidden', 'true');
                    name.setAttribute('aria-hidden', 'true');
                    node.replaceChildren(number, name);
                    node.title = item.title;
                    const textRange = document.createRange();
                    textRange.selectNodeContents(name);
                    node.style.setProperty('--place-label-width', Math.min(220, Math.max(60, Math.ceil(textRange.getBoundingClientRect().width) + 24)) + 'px');
                }
                this.markers.push({node: node, lat: item.latitude, lng: item.longitude, item: item});
            }
            this.render();
        }

        highlight(listingId) {
            for (const marker of this.markers) { marker.node.classList.toggle('is-active', marker.item.id === listingId); }
        }

        setPin(lat, lng) {
            if (!this.pin) {
                const node = element('div', 'h2h-map-pin');
                node.setAttribute('aria-hidden', 'true');
                this.markerPane.append(node);
                this.pin = {node: node, lat: lat, lng: lng, rotate: ' rotate(-45deg)'};
            }
            this.pin.lat = lat;
            this.pin.lng = lng;
            this.render();
        }

        clearPin() {
            if (this.pin) { this.pin.node.remove(); this.pin = null; }
        }

        openPopup(marker, fromKeyboard) {
            this.closePopup(false);
            const item = marker.item;
            const close = element('button', 'h2h-map-popup-close', '×');
            close.type = 'button';
            close.setAttribute('aria-label', window.h2hT('Đóng thông tin chỗ ở'));
            close.dataset.popupClose = 'true';
            const image = element('img');
            if (!item.service) { image.src = item.photo; }
            image.alt = '';
            const body = element('div', 'h2h-map-popup-body');
            const title = element('a', '', item.title);
            title.href = item.url;
            const meta = element('p', 'h2h-map-popup-meta', item.city + ' · ' + item.property_type + ' · ' + window.h2hT('{count} khách', {count: item.max_guests}));
            const price = element('p', 'h2h-map-popup-price', item.price_label + ' ' + window.h2hT('/ đêm') + (item.review_count > 0 ? ' · ★ ' + item.rating.toFixed(1) : ''));
            body.append(title, meta, price);
            this.popup.replaceChildren(close, image, body);
            if (item.service) {
                close.setAttribute('aria-label', window.h2hT('Đóng thông tin địa điểm'));
                meta.textContent = item.category_label + (item.address ? ' · ' + item.address : '');
                price.textContent = item.opening_hours ? window.h2hT('Giờ mở cửa') + ': ' + item.opening_hours : window.h2hT('Chưa có thông tin giờ mở cửa');
                const directions = element('a', '', window.h2hT('Chỉ đường'));
                directions.href = 'https://www.google.com/maps/dir/?api=1&destination=' + item.latitude + ',' + item.longitude;
                directions.target = '_blank';
                directions.rel = 'noopener noreferrer';
                const googleMaps = element('a', 'btn btn-outline-primary btn-sm mt-2', window.h2hT('Mở Google Maps'));
                googleMaps.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(item.latitude + ',' + item.longitude);
                googleMaps.target = '_blank';
                googleMaps.rel = 'noopener noreferrer';
                body.append(directions, element('br'), googleMaps);
                this.popup.replaceChildren(close, body);
            }
            this.popup.setAttribute('aria-label', item.title);
            this.popup.hidden = false;
            this.openMarker = marker;
            marker.node.setAttribute('aria-expanded', 'true');
            this.positionPopup(this.origin());
            if (fromKeyboard) { title.focus(); }
            if (this.options.onMarkerSelect) { this.options.onMarkerSelect(item.id); }
        }

        closePopup(returnFocus) {
            if (!this.openMarker) { return; }
            const marker = this.openMarker;
            this.openMarker = null;
            this.popup.hidden = true;
            this.popup.replaceChildren();
            marker.node.setAttribute('aria-expanded', 'false');
            if (returnFocus && !marker.node.hidden) { marker.node.focus(); }
            if (this.options.onMarkerSelect) { this.options.onMarkerSelect(null); }
        }

        positionPopup(view) {
            if (!this.openMarker || this.popup.hidden) { return; }
            const marker = this.openMarker;
            const width = this.popup.offsetWidth;
            const height = this.popup.offsetHeight;
            const left = clamp(marker.x - width / 2, 8, Math.max(8, view.width - width - 8));
            let top = marker.y - 36 - height;
            if (top < 8) { top = Math.min(marker.y + 8, Math.max(8, view.height - height - 8)); }
            this.popup.style.transform = 'translate(' + Math.round(left) + 'px,' + Math.round(top) + 'px)';
        }

        onClick(event) {
            if (event.currentTarget === this.zoomInButton || event.currentTarget === this.zoomOutButton) {
                const delta = event.currentTarget === this.zoomInButton ? 1 : -1;
                if (this.zoomAround(this.zoom + delta)) { this.moved(true); }
                return;
            }
            if (event.currentTarget === this.popup) {
                if (event.target.closest('[data-popup-close]')) { this.closePopup(true); }
                return;
            }
            const button = event.target.closest('.h2h-map-marker');
            if (!button) { return; }
            for (const marker of this.markers) {
                if (marker.node === button) {
                    if (this.openMarker === marker) { this.closePopup(false); } else { this.openPopup(marker, event.detail === 0); }
                }
            }
        }

        onKeyDown(event) {
            if (event.altKey || event.ctrlKey || event.metaKey) { return; }
            const pans = {ArrowLeft: [-PAN_STEP, 0], ArrowRight: [PAN_STEP, 0], ArrowUp: [0, -PAN_STEP], ArrowDown: [0, PAN_STEP]};
            if (pans[event.key] && this.interactive && !event.target.closest('.h2h-map-popup')) {
                event.preventDefault();
                this.panBy(pans[event.key][0], pans[event.key][1]);
                this.moved(true);
            } else if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                if (this.zoomAround(this.zoom + 1)) { this.moved(true); }
            } else if (event.key === '-' || event.key === '_') {
                event.preventDefault();
                if (this.zoomAround(this.zoom - 1)) { this.moved(true); }
            } else if (event.key === 'Escape' && this.openMarker) {
                event.preventDefault();
                this.closePopup(true);
            } else if ((event.key === 'Enter' || event.key === ' ') && event.target === this.viewport && this.options.onCenterSelect) {
                event.preventDefault();
                this.options.onCenterSelect(this.center.lat, wrapLongitude(this.center.lng));
            }
        }

        onPointerDown(event) {
            if (event.button !== 0 || event.target.closest('.h2h-map-marker, .h2h-map-popup')) { return; }
            if (!this.interactive) { return; }
            this.drag = {id: event.pointerId, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, moved: false};
            this.viewport.setPointerCapture(event.pointerId);
        }

        onPointerMove(event) {
            if (!this.drag || event.pointerId !== this.drag.id) { return; }
            if (!this.drag.moved && Math.abs(event.clientX - this.drag.startX) + Math.abs(event.clientY - this.drag.startY) < 5) { return; }
            if (!this.drag.moved) { this.drag.moved = true; this.viewport.classList.add('is-dragging'); }
            this.panBy(this.drag.x - event.clientX, this.drag.y - event.clientY);
            this.drag.x = event.clientX;
            this.drag.y = event.clientY;
        }

        onPointerUp(event, cancelled) {
            if (!this.drag || event.pointerId !== this.drag.id) { return; }
            const drag = this.drag;
            this.drag = null;
            this.viewport.classList.remove('is-dragging');
            if (this.viewport.hasPointerCapture(event.pointerId)) { this.viewport.releasePointerCapture(event.pointerId); }
            if (drag.moved) { this.moved(true); return; }
            if (cancelled) { return; }
            this.closePopup(false);
            if (this.options.onPointSelect) {
                const rect = this.viewport.getBoundingClientRect();
                const view = this.origin();
                const point = unproject(view.x + event.clientX - rect.left, view.y + event.clientY - rect.top, this.zoom);
                this.options.onPointSelect(clamp(point.lat, -MAX_LATITUDE, MAX_LATITUDE), wrapLongitude(point.lng));
            }
        }

        onWheel(event) {
            event.preventDefault();
            const now = Date.now();
            if (now - this.lastWheel < 250 || event.deltaY === 0) { return; }
            this.lastWheel = now;
            const rect = this.viewport.getBoundingClientRect();
            if (this.zoomAround(this.zoom + (event.deltaY < 0 ? 1 : -1), event.clientX - rect.left, event.clientY - rect.top)) { this.moved(true); }
        }

        onDoubleClick(event) {
            if (event.target.closest('.h2h-map-marker, .h2h-map-popup')) { return; }
            event.preventDefault();
            const rect = this.viewport.getBoundingClientRect();
            if (this.zoomAround(this.zoom + 1, event.clientX - rect.left, event.clientY - rect.top)) { this.moved(true); }
        }

        moved(byUser) {
            if (this.options.onMove) { this.options.onMove(byUser); }
        }
    }

    /** Map search page: markers and side list follow the visible box. */
    class MapSearchPage {
        constructor(container) {
            this.endpoint = container.dataset.api;
            this.filters = parseJson(container.dataset.filters, {});
            this.location = container.dataset.location || '';
            this.useLocation = this.location !== '';
            this.list = document.querySelector('[data-map-list]');
            this.count = document.querySelector('[data-map-count]');
            this.empty = document.querySelector('[data-map-empty]');
            this.error = document.querySelector('[data-map-error]');
            this.results = this.list.closest('section');
            this.timer = 0;
            this.request = 0;
            this.abort = null;
            this.runQuery = this.query.bind(this);
            this.areaButton=document.querySelector('[data-search-area]');
            this.areaButton?.addEventListener('click', this.searchArea.bind(this));
            this.typeLabels = parseJson(container.dataset.typeLabels, {});
            const items = this.localize(parseJson(container.dataset.initial, []));
            this.map = new SlippyMap(container, {center: VIETNAM, zoom: VIETNAM.zoom, label: container.dataset.label,
                onMove: this.onMove.bind(this), onMarkerSelect: this.onMarkerSelect.bind(this)});
            this.map.setMarkers(items);
            const place = knownPlace(this.location);
            if (!this.map.fitPoints(items, 13) && place) { this.map.setView(place.lat, place.lng, 12); }
            this.list.addEventListener('mouseover', this);
            this.list.addEventListener('focusin', this);
            this.list.addEventListener('mouseleave', this);
            this.list.addEventListener('focusout', this);
        }

        /** The map API sends property type names in Vietnamese; the page supplies them in the interface language. */
        localize(items) {
            for (const item of items) {
                if (Object.prototype.hasOwnProperty.call(this.typeLabels, item.property_type)) { item.property_type = this.typeLabels[item.property_type]; }
            }
            return items;
        }

        handleEvent(event) {
            const item = event.target.closest ? event.target.closest('[data-listing-id]') : null;
            if ((event.type === 'mouseover' || event.type === 'focusin') && item) {
                this.map.highlight(Number(item.dataset.listingId));
            } else if (event.type === 'mouseleave' || event.type === 'focusout') {
                this.map.highlight(null);
            }
        }

        onMove(byUser) {
            if (!byUser) { return; }
            if(this.areaButton)this.areaButton.hidden=false;
        }

        searchArea() {
            this.useLocation = false;
            delete this.filters.city;
            this.query();
        }

        onMarkerSelect(listingId) {
            for (const item of this.list.querySelectorAll('[data-listing-id]')) {
                item.classList.toggle('is-active', Number(item.dataset.listingId) === listingId);
            }
        }

        query() {
            if (this.abort) { this.abort.abort(); }
            this.abort = new AbortController();
            const bounds = this.map.getBounds();
            const params = new URLSearchParams();
            for (const name of Object.keys(this.filters)) {
                if (Array.isArray(this.filters[name])) { for (const value of this.filters[name]) { params.append(name + '[]', value); } }
                else if (this.filters[name] !== '' && this.filters[name] !== null) { params.set(name, this.filters[name]); }
            }
            if (this.useLocation) { params.set('location', this.location); }
            params.set('north', bounds.north.toFixed(6));
            params.set('south', bounds.south.toFixed(6));
            params.set('east', bounds.east.toFixed(6));
            params.set('west', bounds.west.toFixed(6));
            this.pendingParams=params;
            const request = ++this.request;
            this.results.setAttribute('aria-busy', 'true');
            fetch(this.endpoint + '?' + params.toString(), {headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: this.abort.signal})
                .then(readJson)
                .then(this.receive.bind(this, request))
                .catch(this.fail.bind(this, request));
        }

        receive(request, result) {
            if (request !== this.request) { return; }
            this.results.removeAttribute('aria-busy');
            if (!result.body || !result.body.success) {
                this.showError(result.body && result.body.message ? result.body.message : window.h2hT('Không tải được chỗ ở trong khu vực này.'));
                return;
            }
            this.showError('');
            if(this.areaButton)this.areaButton.hidden=true;
            const pageUrl=new URL(location.href);pageUrl.search=this.pendingParams.toString();history.replaceState(history.state,'',pageUrl);
            const listLink=document.querySelector('[data-map-list-link]');
            if(listLink){const listUrl=new URL(listLink.href);listUrl.search=this.pendingParams.toString();listLink.href=listUrl.href;}
            const locationField=document.querySelector('#map-location');if(locationField&&!this.useLocation)locationField.value='';
            const data = result.body.data;
            const listings = this.localize(data.listings);
            this.map.setMarkers(listings);
            this.renderList(listings, data.total);
        }

        fail(request, error) {
            if (request !== this.request || (error && error.name === 'AbortError')) { return; }
            this.results.removeAttribute('aria-busy');
            this.showError(window.h2hT('Không tải được chỗ ở trong khu vực này. Vui lòng kiểm tra kết nối và thử lại.'));
        }

        showError(message) {
            this.error.textContent = message;
            this.error.hidden = message === '';
        }

        renderList(items, total) {
            const rows = [];
            for (const item of items) {
                const row = element('li', 'discovery-map-item');
                row.dataset.listingId = String(item.id);
                const link = element('a', 'discovery-map-item-link');
                link.href = item.url;
                const image = element('img');
                image.src = item.photo;
                image.alt = '';
                image.loading = 'lazy';
                image.width = 96;
                image.height = 72;
                const body = element('span', 'discovery-map-item-body');
                const price = element('span', 'discovery-map-item-price', item.price_label + ' ');
                price.append(element('span', '', window.h2hT('/ đêm')));
                if (item.review_count > 0) { price.append(' · ★ ' + item.rating.toFixed(1)); }
                body.append(element('strong', '', item.title),
                    element('span', 'discovery-map-item-meta', item.city + ' · ' + item.property_type + ' · ' + window.h2hT('{count} khách', {count: item.max_guests})), price);
                link.append(image, body);
                row.append(link);
                rows.push(row);
            }
            this.list.replaceChildren.apply(this.list, rows);
            const label = total > items.length
                ? window.h2hT('Hiển thị {shown}/{total} chỗ ở trong khu vực này — phóng to để xem thêm', {shown: items.length, total: total})
                : window.h2hT('{total} chỗ ở trong khu vực này', {total: total});
            this.count.replaceChildren(element('strong', '', label));
            this.empty.hidden = items.length > 0;
        }
    }

    const SERVICE_CATEGORIES = {
        food: {label: 'Quán ăn', selector: '[amenity~"^(restaurant|fast_food|food_court)$"]'},
        bar: {label: 'Bar', selector: '[amenity~"^(bar|pub|biergarten)$"]'},
        entertainment: {label: 'Giải trí', selector: '[amenity~"^(cinema|theatre|nightclub|casino|arts_centre)$"]'},
        cafe: {label: 'Quán nước', selector: '[amenity~"^(cafe|ice_cream)$"]'}
    };

    function serviceItems(elements) {
        return elements.flatMap(function (place) {
            const tags = place.tags || {};
            const point = place.center || place;
            const category = Object.values(SERVICE_CATEGORIES).find(function (entry) {
                return new RegExp('^' + entry.selector.match(/\^(.+)\$/)[1] + '$').test(tags.amenity || '');
            });
            if (!category || !Number.isFinite(point.lat) || !Number.isFinite(point.lon)) { return []; }
            return [{service: true, id: place.type + '/' + place.id,
                title: tags.name || tags['name:vi'] || window.h2hT(category.label),
                category_label: window.h2hT(category.label), latitude: point.lat, longitude: point.lon,
                address: [tags['addr:housenumber'], tags['addr:street'], tags['addr:city']].filter(Boolean).join(' '),
                opening_hours: tags.opening_hours || '', url: 'https://www.openstreetmap.org/' + place.type + '/' + place.id}];
        });
    }

    class TourismServices {
        constructor(root) {
            this.root = root;
            this.status = root.querySelector('[data-services-status]');
            this.error = root.querySelector('[data-services-error]');
            this.list = root.querySelector('[data-services-list]');
            this.button = root.querySelector('[data-services-search]');
            this.category = root.querySelector('[data-services-category]');
            this.city = root.querySelector('[data-services-city]');
            const search = document.querySelector('[data-discovery-map="search"]');
            const initial = parseJson(search?.dataset.initial, []);
            const first = initial[0];
            const center = knownPlace(search?.dataset.location || '') || (first ? {lat: first.latitude, lng: first.longitude} : {lat: 16.0544, lng: 108.2022});
            const names = ['Hà Nội', 'Hồ Chí Minh', 'Sài Gòn', 'Đà Nẵng', 'Hội An', 'Đà Lạt', 'Nha Trang', 'Huế', 'Phú Quốc', 'Sa Pa', 'Sapa', 'Vũng Tàu', 'Hạ Long', 'Quy Nhơn', 'Cần Thơ', 'Hải Phòng', 'Ninh Bình', 'Phan Thiết', 'Mũi Né', 'Côn Đảo'];
            KNOWN_PLACES.forEach((place, index) => {
                const option = element('option', '', names[index]);
                option.value = String(index);
                this.city.append(option);
            });
            this.map = new SlippyMap(root.querySelector('[data-services-map]'), {center: center, zoom: 16,
                label: root.querySelector('[data-services-map]').dataset.label,
                onMove: (byUser) => { if (byUser) { this.city.value = ''; } },
                onMarkerSelect: (id) => {
                    for (const row of this.list.children) { row.classList.toggle('is-active', row.dataset.serviceId === id); }
                }});
            this.city.addEventListener('change', () => {
                if (this.city.value !== '') {
                    const place = KNOWN_PLACES[Number(this.city.value)];
                    this.map.setView(place[1], place[2], 16);
                }
            });
            root.querySelector('[data-services-form]').addEventListener('submit', (event) => { event.preventDefault(); this.query(); });
        }

        async query() {
            const bounds = this.map.getBounds();
            this.error.hidden = true;
            if (bounds.north - bounds.south > 0.15 || bounds.east - bounds.west > 0.15 || bounds.east < bounds.west) {
                this.error.textContent = window.h2hT('Khu vực quá rộng. Hãy phóng to bản đồ rồi tìm lại.');
                this.error.hidden = false;
                return;
            }
            this.abort?.abort();
            const controller = new AbortController();
            this.abort = controller;
            const timeout = setTimeout(() => controller.abort(), 30000);
            this.button.disabled = true;
            this.root.setAttribute('aria-busy', 'true');
            this.status.textContent = window.h2hT('Đang tải địa điểm dịch vụ…');
            const categories = this.category.value === 'all' ? Object.values(SERVICE_CATEGORIES) : [SERVICE_CATEGORIES[this.category.value]];
            const box = [bounds.south, bounds.west, bounds.north, bounds.east].join(',');
            const query = '[out:json][timeout:20];(' + categories.map((entry) => 'nwr' + entry.selector + '(' + box + ');').join('') + ');out center 201;';
            try {
                const response = await fetch('https://overpass-api.de/api/interpreter', {
                    method: 'POST', body: new URLSearchParams({data: query}), signal: controller.signal, credentials: 'omit'
                });
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                const data = await response.json();
                if (!Array.isArray(data.elements) || data.remark) { throw new Error('Incomplete response'); }
                if (this.abort !== controller) { return; }
                const items = serviceItems(data.elements).slice(0, 200);
                this.map.setMarkers(items);
                renderServiceList(this.list, this.map, items);
                this.status.textContent = items.length ? window.h2hT('{count} địa điểm dịch vụ trong khu vực này.', {count: items.length}) : window.h2hT('Chưa tìm thấy dịch vụ. Hãy thử khu vực khác hoặc chọn tất cả dịch vụ.');
                if (data.elements.length > 200) { this.status.textContent += ' ' + window.h2hT('Đang hiển thị tối đa 200 địa điểm. Phóng to để xem thêm.'); }
            } catch (error) {
                if (this.abort !== controller) { return; }
                this.status.textContent = window.h2hT('Chưa cập nhật kết quả dịch vụ.');
                this.error.textContent = window.h2hT('Không tải được địa điểm. Vui lòng thử lại sau.');
                this.error.hidden = false;
            } finally {
                clearTimeout(timeout);
                if (this.abort === controller) { this.button.disabled = false; this.root.removeAttribute('aria-busy'); }
            }
        }
    }

    function renderServiceList(list, map, items) {
        list.replaceChildren();
        const nearby = list.hasAttribute('data-nearby-list');
        for (const item of items) {
            const row = element('li', 'discovery-map-item');
            row.dataset.serviceId = item.id;
            const select = element('button', nearby ? 'nearby-place' : 'btn btn-ghost text-left justify-content-start w-100');
            select.type = 'button';
            const body = element('span', 'discovery-map-item-body');
            body.append(element('strong', '', item.title), element('span', 'discovery-map-item-meta', item.category_label), element('span', nearby ? 'nearby-place-address' : '', item.address));
            if (nearby) {
                select.setAttribute('aria-pressed', 'false');
                select.append(element('span', 'nearby-place-number', item.marker_label));
            } else if (Number.isFinite(item.distance)) {
                body.append(element('span', 'discovery-map-item-meta', window.h2hT('Cách chỗ ở {distance} m', {distance: item.distance})));
            }
            select.append(body);
            if (nearby && Number.isFinite(item.distance)) {
                const distance = element('span', 'nearby-place-distance', item.distance + ' m');
                distance.setAttribute('aria-label', window.h2hT('Cách chỗ ở {distance} m', {distance: item.distance}));
                select.append(distance);
            }
            select.addEventListener('click', () => {
                map.setView(item.latitude, item.longitude);
                map.openPopup(map.markers.find((marker) => marker.item.id === item.id), true);
            });
            row.append(select);
            list.append(row);
        }
    }

    class NearbyServices {
        constructor(root) {
            this.root = root;
            this.list = root.querySelector('[data-nearby-list]');
            this.results = root.querySelector('[data-nearby-results]');
            this.status = root.querySelector('[data-nearby-status]');
            this.error = root.querySelector('[data-nearby-error]');
            this.message = root.querySelector('[data-nearby-message]');
            this.retry = root.querySelector('[data-nearby-retry]');
            this.category = 'all';
            this.filters = root.querySelectorAll('[data-nearby-category]');
            this.home = {lat: Number(root.dataset.lat), lng: Number(root.dataset.lng)};
            this.map = new SlippyMap(root.querySelector('[data-nearby-map]'), {
                center: this.home, zoom: 17, label: root.querySelector('[data-nearby-map]').dataset.label,
                onMarkerSelect: (id) => {
                    for (const row of this.list.children) {
                        const selected = row.dataset.serviceId === id;
                        row.classList.toggle('is-active', selected);
                        row.querySelector('button').setAttribute('aria-pressed', String(selected));
                    }
                }
            });
            this.map.setPin(this.home.lat, this.home.lng);
            for (const button of this.filters) {
                button.addEventListener('click', () => {
                    if (this.category === button.value) { return; }
                    this.category = button.value;
                    for (const filter of this.filters) { filter.setAttribute('aria-pressed', String(filter === button)); }
                    this.query();
                });
            }
            this.retry.addEventListener('click', () => this.query());
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) { observer.disconnect(); this.query(); }
                }, {rootMargin: '150px'});
                observer.observe(root);
            } else { this.query(); }
        }

        async query() {
            this.abort?.abort();
            const controller = new AbortController();
            this.abort = controller;
            const timeout = setTimeout(() => controller.abort(), 15000);
            this.error.hidden = true;
            this.results.setAttribute('aria-busy', 'true');
            this.retry.disabled = true;
            this.status.textContent = window.h2hT('Đang tải dịch vụ gần đây…');
            try {
                const endpoint = new URL(this.root.dataset.api, location.href);
                endpoint.searchParams.set('category', this.category);
                const response = await fetch(endpoint, {headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: controller.signal});
                const result = await response.json();
                if (this.abort !== controller) { return; }
                if (!response.ok || !result.success || !Array.isArray(result.data?.places)) {
                    throw new Error(result.message || window.h2hT('Chưa tải được dịch vụ gần đây. Vui lòng thử lại sau.'));
                }
                const items = result.data.places;
                for (const [index, item] of items.entries()) {
                    item.category_label = window.h2hT(item.category_label);
                    item.marker_label = String(index + 1);
                }
                this.map.setMarkers(items);
                this.map.setPin(this.home.lat, this.home.lng);
                this.map.fitPoints(items.concat([{latitude: this.home.lat, longitude: this.home.lng}]), 15);
                this.map.zoomAround(this.map.zoom + 2);
                renderServiceList(this.list, this.map, items);
                this.status.textContent = items.length
                    ? window.h2hT('{count} địa điểm trong bán kính 1 km.', {count: items.length})
                    : window.h2hT('Chưa tìm thấy dịch vụ trong bán kính 1 km. Hãy thử loại dịch vụ khác.');
                if (items.length === result.data.limit) { this.status.textContent += ' ' + window.h2hT('Hiển thị tối đa 40 địa điểm gần nhất.'); }
            } catch (error) {
                if (this.abort !== controller) { return; }
                this.status.textContent = window.h2hT('Chưa cập nhật kết quả dịch vụ.');
                this.message.textContent = window.h2hT('Chưa tải được dịch vụ gần đây. Vui lòng thử lại sau.');
                this.error.hidden = false;
            } finally {
                clearTimeout(timeout);
                if (this.abort === controller) { this.results.removeAttribute('aria-busy'); this.retry.disabled = false; }
            }
        }
    }

    function readJson(response) {
        return response.json().then(attachStatus.bind(null, response.status));
    }

    function attachStatus(status, body) { return {status: status, body: body}; }

    /** Host listing form: optional coordinates typed or picked on the map. */
    class LocationPicker {
        constructor(root) {
            this.latitude = root.querySelector('[data-picker-lat]');
            this.longitude = root.querySelector('[data-picker-lng]');
            this.toggleButton = root.querySelector('[data-picker-toggle]');
            this.clearButton = root.querySelector('[data-picker-clear]');
            this.panel = document.getElementById(this.toggleButton.getAttribute('aria-controls'));
            this.status = root.querySelector('[data-picker-status]');
            this.mapElement = this.panel.querySelector('[data-discovery-map="picker"]');
            this.map = null;
            this.toggleButton.addEventListener('click', this);
            this.clearButton.addEventListener('click', this);
            this.latitude.addEventListener('input', this);
            this.longitude.addEventListener('input', this);
            root.querySelector('[data-address-search]')?.addEventListener('click', async event => {
                const button = event.currentTarget;
                const results = root.querySelector('[data-address-results]');
                const address = [document.getElementById('address').value, document.getElementById('city').value].filter(Boolean).join(', ');
                button.disabled = true; results.textContent = window.h2hT('Đang tìm địa chỉ…');
                try {
                    const response = await fetch(button.dataset.addressApi + '?q=' + encodeURIComponent(address), {headers:{Accept:'application/json'}, signal:AbortSignal.timeout(15000)});
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message);
                    results.replaceChildren();
                    if (!data.data.places.length) results.textContent = window.h2hT('Không tìm thấy địa chỉ. Hãy nhập rõ số nhà, đường và thành phố.');
                    data.data.places.forEach(place => {
                        const choice = document.createElement('button'); choice.type = 'button'; choice.className = 'btn btn-ghost d-block mb-2'; choice.textContent = place.label;
                        choice.addEventListener('click', () => { this.pick(place.latitude, place.longitude); if (this.panel.hidden) this.toggle(); this.map.setView(place.latitude,place.longitude,16); results.replaceChildren(); });
                        results.append(choice);
                    });
                } catch (error) { results.textContent = error.message || window.h2hT('Chưa tìm được địa chỉ. Vui lòng thử lại.'); }
                finally { button.disabled = false; }
            });
            this.validate();
        }

        handleEvent(event) {
            if (event.currentTarget === this.toggleButton) { this.toggle(); }
            else if (event.currentTarget === this.clearButton) { this.clear(); }
            else { this.validate(); this.syncPin(); }
        }

        value(input) {
            const text = input.value.trim().replace(',', '.');
            return text === '' ? null : Number(text);
        }

        validate() {
            const lat = this.value(this.latitude);
            const lng = this.value(this.longitude);
            let latMessage = '';
            let lngMessage = '';
            if ((lat === null) !== (lng === null)) {
                const message = window.h2hT('Vui lòng nhập cả vĩ độ và kinh độ, hoặc để trống cả hai.');
                if (lat === null) { latMessage = message; } else { lngMessage = message; }
            }
            if (lat !== null && !(Math.abs(lat) <= 90)) { latMessage = window.h2hT('Vĩ độ phải từ -90 đến 90.'); }
            if (lng !== null && !(Math.abs(lng) <= 180)) { lngMessage = window.h2hT('Kinh độ phải từ -180 đến 180.'); }
            this.latitude.setCustomValidity(latMessage);
            this.longitude.setCustomValidity(lngMessage);
            return latMessage === '' && lngMessage === '' && lat !== null && lng !== null ? {lat: lat, lng: lng} : null;
        }

        toggle() {
            const opening = this.panel.hidden;
            this.panel.hidden = !opening;
            this.toggleButton.setAttribute('aria-expanded', String(opening));
            if (!opening) { return; }
            const point = this.validate();
            if (!this.map) {
                this.map = new SlippyMap(this.mapElement, {center: point || VIETNAM, zoom: point ? 15 : VIETNAM.zoom,
                    label: this.mapElement.dataset.label, onPointSelect: this.pick.bind(this), onCenterSelect: this.pick.bind(this)});
            }
            this.map.render();
            this.syncPin();
            if (point) { this.map.setView(point.lat, point.lng); }
        }

        pick(lat, lng) {
            this.latitude.value = lat.toFixed(6);
            this.longitude.value = lng.toFixed(6);
            this.validate();
            this.syncPin();
            this.status.textContent = window.h2hT('Đã chọn vị trí: {lat}, {lng}.', {lat: this.latitude.value, lng: this.longitude.value});
        }

        clear() {
            this.latitude.value = '';
            this.longitude.value = '';
            this.validate();
            this.syncPin();
            this.status.textContent = window.h2hT('Đã xóa tọa độ; chỗ ở sẽ không hiện trên bản đồ.');
        }

        syncPin() {
            if (!this.map) { return; }
            const point = this.validate();
            if (point) { this.map.setPin(point.lat, point.lng); } else { this.map.clearPin(); }
        }
    }

    function initSearch(container) {
        container.h2hMap = new MapSearchPage(container);
        const form = document.querySelector('[data-map-address-form]');
        form?.addEventListener('submit', async event => {
            event.preventDefault();
            const results = form.querySelector('[data-map-address-results]');
            const button = form.querySelector('button'); button.disabled = true;
            results.textContent = window.h2hT('Đang tìm địa chỉ…');
            try {
                const response = await fetch(form.dataset.api+'?q='+encodeURIComponent(form.elements.address.value), {headers:{Accept:'application/json'},signal:AbortSignal.timeout(15000)});
                const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.message);
                results.replaceChildren();
                if (!data.data.places.length) results.textContent = window.h2hT('Không tìm thấy địa chỉ. Hãy nhập rõ số nhà, đường và thành phố.');
                data.data.places.forEach(place => {
                    const choice = document.createElement('button'); choice.type = 'button'; choice.className='btn btn-ghost d-block'; choice.textContent=place.label;
                    choice.addEventListener('click', () => { container.h2hMap.map.setView(place.latitude,place.longitude,14); container.h2hMap.searchArea(); results.replaceChildren(); }); results.append(choice);
                });
            } catch(error) { results.textContent = error.message || window.h2hT('Chưa tìm được địa chỉ. Vui lòng thử lại.'); }
            finally { button.disabled=false; }
        });
    }

    function initStatic(container) {
        const lat = Number(container.dataset.lat);
        const lng = Number(container.dataset.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) { return; }
        const map = new SlippyMap(container, {center: {lat: lat, lng: lng}, zoom: 14, interactive: false, label: container.dataset.label});
        map.setPin(lat, lng);
    }

    function initPicker(root) { root.h2hPicker = new LocationPicker(root); }

    function initialize() {
        document.querySelectorAll('[data-discovery-map="search"]').forEach(initSearch);
        document.querySelectorAll('[data-discovery-map="static"]').forEach(initStatic);
        document.querySelectorAll('[data-location-picker]').forEach(initPicker);
        document.querySelectorAll('[data-tourism-services]').forEach((root) => { root.h2hServices = new TourismServices(root); });
        document.querySelectorAll('[data-nearby-services]').forEach((root) => { root.h2hNearby = new NearbyServices(root); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
})();
