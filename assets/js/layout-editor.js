/**
 * Custom Seat Layout Editor JavaScript
 * Provides interactive drag-and-drop functionality for creating custom bus layouts
 */

(function($) {
    'use strict';

    class SeatLayoutEditor {
        constructor(container) {
            this.container = $(container);
            this.canvas = this.container.find('#vsbbm-layout-canvas');
            this.selectedTool = null;
            this.layoutData = null;
            this.isDirty = false;

            this.init();
        }

        init() {
            this.bindEvents();
            this.loadLayoutData();
            this.updateCanvasInfo();
        }

        bindEvents() {
            const self = this;

            // Tool selection
            this.container.on('click', '.tool-item', function() {
                const $tool = $(this);
                self.selectTool($tool.data('type'), $tool.data('class'));
            });

            // Canvas cell interactions
            this.container.on('click', '.canvas-cell', function() {
                const $cell = $(this);
                self.handleCellClick($cell);
            });

            // Save layout
            this.container.on('click', '#vsbbm-save-layout', function() {
                self.saveLayout();
            });

            // Load template
            this.container.on('click', '#vsbbm-load-template', function() {
                self.showTemplateModal();
            });

            // Clear canvas
            this.container.on('click', '#vsbbm-clear-canvas', function() {
                if (confirm(vsbbm_layout_editor.strings.confirm_delete)) {
                    self.clearCanvas();
                }
            });

            // Template selection
            this.container.on('click', '.template-item', function() {
                const template = $(this).data('template');
                self.loadTemplate(template);
                self.hideTemplateModal();
            });

            // Modal close
            this.container.on('click', '.modal-close', function() {
                self.hideTemplateModal();
            });

            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                if (e.key === 'Delete' || e.key === 'Backspace') {
                    self.deleteSelectedCells();
                }
            });
        }

        selectTool(type, className) {
            this.selectedTool = { type: type, class: className };

            // Update UI
            this.container.find('.tool-item').removeClass('active');
            this.container.find(`.tool-item[data-type="${type}"][data-class="${className}"]`).addClass('active');

            // Update cursor
            this.updateCursor();
        }

        updateCursor() {
            const cursor = this.selectedTool ? 'crosshair' : 'default';
            this.canvas.find('.canvas-grid').css('cursor', cursor);
        }

        handleCellClick($cell) {
            if (!this.selectedTool) return;

            const x = parseInt($cell.data('x'));
            const y = parseInt($cell.data('y'));

            // Remove existing cell data
            this.removeCellData(x, y);

            // Add new cell data
            const cellData = {
                x: x,
                y: y,
                type: this.selectedTool.type
            };

            if (this.selectedTool.class) {
                cellData.class = this.selectedTool.class;
            }

            // Add seat number if it's a seat
            if (this.selectedTool.type === 'seat') {
                cellData.number = this.getNextSeatNumber();
            }

            this.addCellData(cellData);
            this.updateCellDisplay($cell, cellData);
            this.markDirty();
            this.updateCanvasInfo();
        }

        removeCellData(x, y) {
            if (!this.layoutData || !this.layoutData.layout) return;

            this.layoutData.layout = this.layoutData.layout.filter(cell =>
                !(cell.x === x && cell.y === y)
            );
        }

        addCellData(cellData) {
            if (!this.layoutData.layout) {
                this.layoutData.layout = [];
            }

            this.layoutData.layout.push(cellData);
        }

        updateCellDisplay($cell, cellData) {
            // Remove existing classes
            $cell.removeClass().addClass('canvas-cell');

            // Add new classes
            $cell.addClass('cell-' + cellData.type);
            if (cellData.class) {
                $cell.addClass('cell-' + cellData.class);
            }

            // Update data attributes
            $cell.attr('data-type', cellData.type);
            if (cellData.class) {
                $cell.attr('data-class', cellData.class);
            }
            if (cellData.number) {
                $cell.attr('data-number', cellData.number);
            }

            // Update content
            $cell.html(this.getCellContent(cellData));
        }

        getCellContent(cellData) {
            switch (cellData.type) {
                case 'seat':
                    const icon = cellData.class === 'vip' ? '💺' : '🪑';
                    const number = cellData.number || '';
                    return icon + '<br><small>' + number + '</small>';
                case 'aisle':
                    return '🚶';
                case 'space':
                    return '⬜';
                case 'stairs':
                    return '🪜';
                case 'driver':
                    return '👤';
                default:
                    return '';
            }
        }

        getNextSeatNumber() {
            if (!this.layoutData || !this.layoutData.layout) return 1;

            const seatNumbers = this.layoutData.layout
                .filter(cell => cell.type === 'seat' && cell.number)
                .map(cell => cell.number)
                .sort((a, b) => a - b);

            for (let i = 1; i <= seatNumbers.length + 1; i++) {
                if (seatNumbers.indexOf(i) === -1) {
                    return i;
                }
            }

            return seatNumbers.length + 1;
        }

        loadLayoutData() {
            const layoutJson = this.container.find('#vsbbm_custom_layout').val();
            try {
                this.layoutData = JSON.parse(layoutJson);
            } catch (e) {
                this.layoutData = this.getDefaultLayout();
            }

            this.renderCanvas();
        }

        getDefaultLayout() {
            return {
                grid: { rows: 8, cols: 5 },
                layout: [],
                metadata: { total_seats: 0, classes: ['window', 'aisle', 'middle', 'vip'] }
            };
        }

        renderCanvas() {
            if (!this.layoutData) return;

            const $grid = this.canvas.find('.canvas-grid');
            $grid.empty();

            const rows = this.layoutData.grid.rows;
            const cols = this.layoutData.grid.cols;

            // Create lookup for faster access
            const cellLookup = {};
            if (this.layoutData.layout) {
                this.layoutData.layout.forEach(cell => {
                    const key = cell.x + '-' + cell.y;
                    cellLookup[key] = cell;
                });
            }

            for (let y = 0; y < rows; y++) {
                for (let x = 0; x < cols; x++) {
                    const key = x + '-' + y;
                    const cellData = cellLookup[key];

                    const $cell = $('<div>', {
                        class: 'canvas-cell',
                        'data-x': x,
                        'data-y': y
                    });

                    if (cellData) {
                        $cell.addClass('cell-' + cellData.type);
                        if (cellData.class) {
                            $cell.addClass('cell-' + cellData.class);
                        }
                        $cell.attr('data-type', cellData.type);
                        if (cellData.class) {
                            $cell.attr('data-class', cellData.class);
                        }
                        if (cellData.number) {
                            $cell.attr('data-number', cellData.number);
                        }
                        $cell.html(this.getCellContent(cellData));
                    } else {
                        $cell.addClass('cell-empty').attr('data-type', 'empty');
                    }

                    $grid.append($cell);
                }
            }

            this.updateCanvasInfo();
        }

        updateCanvasInfo() {
            if (!this.layoutData) return;

            const totalSeats = this.layoutData.layout ?
                this.layoutData.layout.filter(cell => cell.type === 'seat').length : 0;

            this.container.find('#canvas-size').text(
                vsbbm_layout_editor.strings.canvas_size.replace('%dx%d',
                    this.layoutData.grid.rows + 'x' + this.layoutData.grid.cols)
            );
            this.container.find('#total-seats').text(
                vsbbm_layout_editor.strings.total_seats.replace('%d', totalSeats)
            );

            this.layoutData.metadata.total_seats = totalSeats;
        }

        saveLayout() {
            if (!this.layoutData) return;

            // Update hidden input
            this.container.find('#vsbbm_custom_layout').val(JSON.stringify(this.layoutData));

            // Show success message
            this.showNotice(vsbbm_layout_editor.strings.save_success, 'success');

            this.isDirty = false;
        }

        showTemplateModal() {
            this.container.find('#vsbbm-template-modal').show();
        }

        hideTemplateModal() {
            this.container.find('#vsbbm-template-modal').hide();
        }

        loadTemplate(templateKey) {
            // This would load predefined templates
            // For now, just show a message
            this.showNotice('Template loading feature coming soon!', 'info');
        }

        clearCanvas() {
            if (!this.layoutData) return;

            this.layoutData.layout = [];
            this.renderCanvas();
            this.markDirty();
        }

        deleteSelectedCells() {
            // Implementation for deleting selected cells
            this.showNotice('Delete functionality coming soon!', 'info');
        }

        markDirty() {
            this.isDirty = true;
        }

        showNotice(message, type = 'info') {
            // Simple notice display
            alert(message);
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        $('#vsbbm-layout-editor').each(function() {
            new SeatLayoutEditor(this);
        });
    });

})(jQuery);