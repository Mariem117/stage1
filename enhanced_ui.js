/**
 * Enhanced UI JavaScript for Employee Management System
 * Features: Search, Filter, Bulk Operations, Responsive Design
 */

class EmployeeManager {
    constructor() {
        this.employees = [];
        this.filteredEmployees = [];
        this.selectedEmployees = new Set();
        this.currentPage = 1;
        this.itemsPerPage = 10;
        this.sortColumn = '';
        this.sortDirection = 'asc';
        
        this.init();
    }

    init() {
        this.loadEmployees();
        this.setupEventListeners();
        this.setupResponsiveTable();
        this.setupBulkOperations();
    }

    loadEmployees() {
        // Get employees from the table (server-side rendered)
        const tableRows = document.querySelectorAll('#employeeTableBody tr');
        this.employees = Array.from(tableRows).map((row, index) => {
            const cells = row.querySelectorAll('td');
            return {
                id: row.dataset.userId || index,
                row: row,
                data: {
                    photo: cells[0]?.innerHTML || '',
                    matricule: cells[1]?.textContent.trim() || '',
                    name: cells[2]?.textContent.trim() || '',
                    email: cells[3]?.textContent.trim() || '',
                    phone: cells[4]?.textContent.trim() || '',
                    department: cells[5]?.textContent.trim() || '',
                    position: cells[6]?.textContent.trim() || '',
                    status: cells[7]?.textContent.trim() || '',
                    salary: cells[8]?.textContent.trim() || '',
                    hireDate: cells[9]?.textContent.trim() || '',
                    age: cells[10]?.textContent.trim() || '',
                    ncin: cells[11]?.textContent.trim() || '',
                    cnss: cells[12]?.textContent.trim() || ''
                }
            };
        });
        this.filteredEmployees = [...this.employees];
    }

    setupEventListeners() {
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', this.debounce(() => {
                this.performSearch();
            }, 300));
        }

        // Filter functionality
        const filters = ['departmentFilter', 'statusFilter', 'positionFilter'];
        filters.forEach(filterId => {
            const filter = document.getElementById(filterId);
            if (filter) {
                filter.addEventListener('change', () => this.performSearch());
            }
        });

        // Clear filters
        const clearButton = document.getElementById('clearFilters');
        if (clearButton) {
            clearButton.addEventListener('click', () => this.clearFilters());
        }

        // Select all checkbox
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleSelectAll(e.target.checked);
            });
        }

        // Individual checkboxes
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('employee-checkbox')) {
                this.toggleEmployeeSelection(e.target.dataset.userId, e.target.checked);
            }
        });

        // Bulk operations
        const bulkActionSelect = document.getElementById('bulkAction');
        const applyBulkButton = document.getElementById('applyBulk');
        
        if (bulkActionSelect && applyBulkButton) {
            applyBulkButton.addEventListener('click', () => {
                this.applyBulkAction(bulkActionSelect.value);
            });
        }

        // Table sorting
        document.querySelectorAll('th[data-sort]').forEach(th => {
            th.addEventListener('click', () => {
                this.sortTable(th.dataset.sort);
            });
            th.style.cursor = 'pointer';
            th.innerHTML += ' <span class="sort-indicator">↕</span>';
        });

        // Responsive table toggle
        const viewToggle = document.getElementById('viewToggle');
        if (viewToggle) {
            viewToggle.addEventListener('click', () => this.toggleTableView());
        }

        // Export functionality
        const exportButton = document.getElementById('exportData');
        if (exportButton) {
            exportButton.addEventListener('click', () => this.exportData());
        }
    }

    setupResponsiveTable() {
        const table = document.getElementById('employeeTable');
        if (!table) return;

        // Add responsive wrapper
        if (!table.parentElement.classList.contains('table-responsive')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            table.parentElement.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        }

        // Handle window resize
        window.addEventListener('resize', this.debounce(() => {
            this.adjustTableForScreenSize();
        }, 250));

        this.adjustTableForScreenSize();
    }

    adjustTableForScreenSize() {
        const table = document.getElementById('employeeTable');
        if (!table) return;

        const screenWidth = window.innerWidth;
        const columns = table.querySelectorAll('th, td');

        // Hide/show columns based on screen size
        columns.forEach(cell => {
            const column = cell.classList[0]; // Assuming first class is column identifier
            
            if (screenWidth < 768) {
                // Mobile: Show only essential columns
                const essentialColumns = ['col-name', 'col-status', 'col-actions'];
                if (!essentialColumns.some(col => cell.classList.contains(col))) {
                    cell.style.display = 'none';
                }
            } else if (screenWidth < 1024) {
                // Tablet: Show more columns
                const tabletColumns = ['col-name', 'col-email', 'col-department', 'col-status', 'col-actions'];
                if (!tabletColumns.some(col => cell.classList.contains(col))) {
                    cell.style.display = 'none';
                }
            } else {
                // Desktop: Show all columns
                cell.style.display = '';
            }
        });
    }

    setupBulkOperations() {
        // Show/hide bulk operations based on selection
        this.updateBulkOperationsVisibility();
    }

    performSearch() {
        const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';
        const departmentFilter = document.getElementById('departmentFilter')?.value || '';
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        const positionFilter = document.getElementById('positionFilter')?.value || '';

        this.filteredEmployees = this.employees.filter(employee => {
            const matchesSearch = !searchTerm || 
                Object.values(employee.data).some(value => 
                    value.toLowerCase().includes(searchTerm)
                );

            const matchesDepartment = !departmentFilter || 
                employee.data.department === departmentFilter;

            const matchesStatus = !statusFilter || 
                employee.data.status === statusFilter;

            const matchesPosition = !positionFilter || 
                employee.data.position === positionFilter;

            return matchesSearch && matchesDepartment && matchesStatus && matchesPosition;
        });

        this.updateTableDisplay();
        this.updateResultsCount();
    }

    updateTableDisplay() {
        const tbody = document.getElementById('employeeTableBody');
        if (!tbody) return;

        // Hide all rows first
        this.employees.forEach(employee => {
            employee.row.style.display = 'none';
        });

        // Show filtered rows
        const startIndex = (this.currentPage - 1) * this.itemsPerPage;
        const endIndex = startIndex + this.itemsPerPage;
        const pageEmployees = this.filteredEmployees.slice(startIndex, endIndex);

        pageEmployees.forEach(employee => {
            employee.row.style.display = '';
        });

        this.updatePagination();
    }

    updateResultsCount() {
        const resultsCount = document.getElementById('resultsCount');
        if (resultsCount) {
            resultsCount.textContent = `Showing ${this.filteredEmployees.length} of ${this.employees.length} employees`;
        }
    }

    updatePagination() {
        const totalPages = Math.ceil(this.filteredEmployees.length / this.itemsPerPage);
        const paginationContainer = document.getElementById('pagination');
        
        if (!paginationContainer) return;

        let paginationHTML = '';
        
        // Previous button
        paginationHTML += `
            <button class="pagination-button ${this.currentPage === 1 ? 'disabled' : ''}" 
                    onclick="employeeManager.goToPage(${this.currentPage - 1})" 
                    ${this.currentPage === 1 ? 'disabled' : ''}>
                Previous
            </button>
        `;

        // Page numbers
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= this.currentPage - 2 && i <= this.currentPage + 2)) {
                paginationHTML += `
                    <button class="pagination-button ${i === this.currentPage ? 'active' : ''}" 
                            onclick="employeeManager.goToPage(${i})">
                        ${i}
                    </button>
                `;
            } else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
                paginationHTML += '<span class="pagination-ellipsis">...</span>';
            }
        }

        // Next button
        paginationHTML += `
            <button class="pagination-button ${this.currentPage === totalPages ? 'disabled' : ''}" 
                    onclick="employeeManager.goToPage(${this.currentPage + 1})" 
                    ${this.currentPage === totalPages ? 'disabled' : ''}>
                Next
            </button>
        `;

        paginationContainer.innerHTML = paginationHTML;
    }

    goToPage(page) {
        const totalPages = Math.ceil(this.filteredEmployees.length / this.itemsPerPage);
        if (page >= 1 && page <= totalPages) {
            this.currentPage = page;
            this.updateTableDisplay();
        }
    }

    clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('departmentFilter').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('positionFilter').value = '';
        
        this.currentPage = 1;
        this.performSearch();
    }

    toggleSelectAll(checked) {
        const checkboxes = document.querySelectorAll('.employee-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked;
            this.toggleEmployeeSelection(checkbox.dataset.userId, checked);
        });
    }

    toggleEmployeeSelection(userId, selected) {
        if (selected) {
            this.selectedEmployees.add(userId);
        } else {
            this.selectedEmployees.delete(userId);
        }
        
        this.updateBulkOperationsVisibility();
        this.updateSelectAllCheckbox();
    }

    updateBulkOperationsVisibility() {
        const bulkOperations = document.getElementById('bulkOperations');
        const selectedCount = document.getElementById('selectedCount');
        
        if (bulkOperations) {
            if (this.selectedEmployees.size > 0) {
                bulkOperations.classList.add('active');
                if (selectedCount) {
                    selectedCount.textContent = this.selectedEmployees.size;
                }
            } else {
                bulkOperations.classList.remove('active');
            }
        }
    }

    updateSelectAllCheckbox() {
        const selectAllCheckbox = document.getElementById('selectAll');
        const visibleCheckboxes = document.querySelectorAll('.employee-checkbox:not([style*="display: none"])');
        
        if (selectAllCheckbox && visibleCheckboxes.length > 0) {
            const checkedCount = Array.from(visibleCheckboxes).filter(cb => cb.checked).length;
            selectAllCheckbox.checked = checkedCount === visibleCheckboxes.length;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < visibleCheckboxes.length;
        }
    }

    applyBulkAction(action) {
        if (this.selectedEmployees.size === 0) {
            alert('Please select at least one employee.');
            return;
        }

        const confirmMessage = this.getBulkActionConfirmMessage(action);
        if (!confirm(confirmMessage)) {
            return;
        }

        this.showLoading(true);

        // Prepare data for bulk operation
        const selectedIds = Array.from(this.selectedEmployees);
        
        // Send AJAX request
        fetch('bulk_operations.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: action,
                employee_ids: selectedIds,
                csrf_token: document.querySelector('input[name="csrf_token"]')?.value
            })
        })
        .then(response => response.json())
        .then(data => {
            this.showLoading(false);
            if (data.success) {
                this.showAlert('Bulk operation completed successfully!', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                this.showAlert(data.message || 'Bulk operation failed.', 'error');
            }
        })
        .catch(error => {
            this.showLoading(false);
            this.showAlert('An error occurred during the bulk operation.', 'error');
            console.error('Bulk operation error:', error);
        });
    }

    getBulkActionConfirmMessage(action) {
        const count = this.selectedEmployees.size;
        switch (action) {
            case 'activate':
                return `Are you sure you want to activate ${count} employee(s)?`;
            case 'deactivate':
                return `Are you sure you want to deactivate ${count} employee(s)?`;
            case 'delete':
                return `Are you sure you want to delete ${count} employee(s)? This action cannot be undone.`;
            default:
                return `Are you sure you want to perform this action on ${count} employee(s)?`;
        }
    }

    sortTable(column) {
        if (this.sortColumn === column) {
            this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortColumn = column;
            this.sortDirection = 'asc';
        }

        this.filteredEmployees.sort((a, b) => {
            let aValue = a.data[column] || '';
            let bValue = b.data[column] || '';

            // Handle numeric values
            if (!isNaN(aValue) && !isNaN(bValue)) {
                aValue = parseFloat(aValue);
                bValue = parseFloat(bValue);
            } else {
                aValue = aValue.toString().toLowerCase();
                bValue = bValue.toString().toLowerCase();
            }

            if (aValue < bValue) return this.sortDirection === 'asc' ? -1 : 1;
            if (aValue > bValue) return this.sortDirection === 'asc' ? 1 : -1;
            return 0;
        });

        this.updateTableDisplay();
        this.updateSortIndicators();
    }

    updateSortIndicators() {
        document.querySelectorAll('.sort-indicator').forEach(indicator => {
            indicator.textContent = '↕';
        });

        const currentHeader = document.querySelector(`th[data-sort="${this.sortColumn}"] .sort-indicator`);
        if (currentHeader) {
            currentHeader.textContent = this.sortDirection === 'asc' ? '↑' : '↓';
        }
    }

    toggleTableView() {
        const table = document.getElementById('employeeTable');
        const button = document.getElementById('viewToggle');
        
        if (table.classList.contains('compact-view')) {
            table.classList.remove('compact-view');
            table.classList.add('detailed-view');
            button.textContent = 'Compact View';
        } else {
            table.classList.remove('detailed-view');
            table.classList.add('compact-view');
            button.textContent = 'Detailed View';
        }
    }

    exportData() {
        const csvContent = this.generateCSV();
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        
        if (link.download !== undefined) {
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `employees_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    generateCSV() {
        const headers = ['Name', 'Email', 'Phone', 'Department', 'Position', 'Status', 'Salary', 'Hire Date'];
        const csvRows = [headers.join(',')];

        this.filteredEmployees.forEach(employee => {
            const row = [
                employee.data.name,
                employee.data.email,
                employee.data.phone,
                employee.data.department,
                employee.data.position,
                employee.data.status,
                employee.data.salary,
                employee.data.hireDate
            ].map(field => `"${field}"`);
            csvRows.push(row.join(','));
        });

        return csvRows.join('\n');
    }

    showLoading(show) {
        const loadingElement = document.getElementById('loadingSpinner');
        if (loadingElement) {
            loadingElement.style.display = show ? 'flex' : 'none';
        }
    }

    showAlert(message, type = 'info') {
        const alertContainer = document.getElementById('alertContainer') || document.body;
        const alertElement = document.createElement('div');
        alertElement.className = `alert alert-${type}`;
        alertElement.textContent = message;
        
        alertContainer.appendChild(alertElement);
        
        setTimeout(() => {
            alertElement.remove();
        }, 5000);
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.employeeManager = new EmployeeManager();
});

// Additional utility functions
function confirmDelete(employeeName) {
    return confirm(`Are you sure you want to delete ${employeeName}? This action cannot be undone.`);
}

function updateEmployeeStatus(userId, newStatus) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="user_id" value="${userId}">
        <input type="hidden" name="status" value="${newStatus}">
        <input type="hidden" name="csrf_token" value="${document.querySelector('input[name="csrf_token"]')?.value}">
    `;
    document.body.appendChild(form);
    form.submit();
}
