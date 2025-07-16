
document.addEventListener('DOMContentLoaded', function () {
    // Color palette for charts
    const colors = {
        primary: '#667eea',
        secondary: '#38a169',
        accent: '#e53e3e',
        warning: '#d69e2e',
        info: '#3182ce',
        purple: '#805ad5',
        pink: '#d53f8c',
        teal: '#319795',
        orange: '#dd6b20',
        gray: '#718096'
    };

    const chartColors = [
        colors.primary,
        colors.secondary,
        colors.accent,
        colors.warning,
        colors.info
    ];

    // Common chart options
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2,
        plugins: {
            legend: {
                labels: {
                    color: '#2d3748',
                    font: {
                        size: 12,
                        weight: 'bold'
                    }
                }
            },
            tooltip: {
                backgroundColor: '#2d3748',
                titleColor: '#fff',
                bodyColor: '#e2e8f0',
                borderColor: colors.primary,
                borderWidth: 1
            }
        }
    };

    // Gender Distribution Chart
    const genderCtx = document.getElementById('genderChart');
    if (genderCtx && typeof genderData !== 'undefined') {
        const genderLabels = Object.keys(genderData);
        const genderValues = Object.values(genderData);
        
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: genderLabels.map(label => label.charAt(0).toUpperCase() + label.slice(1)),
                datasets: [{
                    data: genderValues,
                    backgroundColor: chartColors.slice(0, genderLabels.length),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverBorderWidth: 3
                }]
            },
            options: {
                ...commonOptions,
                aspectRatio: 1.5,
                cutout: '60%',
                plugins: {
                    ...commonOptions.plugins,
                    legend: {
                        ...commonOptions.plugins.legend,
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Education Level Chart
    const educationCtx = document.getElementById('educationChart');
    if (educationCtx && typeof educationData !== 'undefined') {
        const educationLabels = Object.keys(educationData);
        const educationValues = Object.values(educationData);
        
        new Chart(educationCtx, {
            type: 'bar',
            data: {
                labels: educationLabels,
                datasets: [{
                    label: 'Number of Employees',
                    data: educationValues,
                    backgroundColor: colors.secondary,
                    borderColor: colors.secondary,
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: '#2f855a'
                }]
            },
            options: {
                ...commonOptions,
                scales: {
                    x: {
                        ticks: {
                            color: '#4a5568',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#edf2f7'
                        }
                    },
                    y: {
                        ticks: {
                            color: '#4a5568',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#edf2f7'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Age Segmentation Chart
    const ageCtx = document.getElementById('ageChart');
    if (ageCtx && typeof ageData !== 'undefined') {
        const ageLabels = Object.keys(ageData);
        const ageValues = Object.values(ageData);
        
        new Chart(ageCtx, {
            type: 'polarArea',
            data: {
                labels: ageLabels.map(label => label + ' years'),
                datasets: [{
                    data: ageValues,
                    backgroundColor: chartColors.slice(0, ageLabels.length).map(color => color + '80'),
                    borderColor: chartColors.slice(0, ageLabels.length),
                    borderWidth: 2
                }]
            },
            options: {
                ...commonOptions,
                aspectRatio: 1.8,
                plugins: {
                    ...commonOptions.plugins,
                    legend: {
                        ...commonOptions.plugins.legend,
                        position: 'right'
                    }
                },
                scales: {
                    r: {
                        ticks: {
                            color: '#4a5568',
                            font: {
                                size: 10
                            }
                        },
                        grid: {
                            color: '#edf2f7'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Department Distribution Chart
    const departmentCtx = document.getElementById('departmentChart');
    if (departmentCtx && typeof departmentData !== 'undefined') {
        const departmentLabels = ['IT', 'HR', 'Finance', 'Marketing', 'Operations'];
        const departmentValues = departmentLabels.map(label => departmentData[label] || 0);
        
        new Chart(departmentCtx, {
            type: 'bar',
            data: {
                labels: departmentLabels,
                datasets: [{
                    label: 'Number of Employees',
                    data: departmentValues,
                    backgroundColor: chartColors,
                    borderColor: chartColors,
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: chartColors.map(color => color.replace(/^\w+/, c => {
                        let rgb = parseInt(c.slice(1), 16);
                        let r = (rgb >> 16) & 255;
                        let g = (rgb >> 8) & 255;
                        let b = rgb & 255;
                        return `rgb(${Math.max(r - 20, 0)}, ${Math.max(g - 20, 0)}, ${Math.max(b - 20, 0)})`;
                    }))
                }]
            },
            options: {
                ...commonOptions,
                scales: {
                    x: {
                        ticks: {
                            color: '#4a5568',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#edf2f7'
                        }
                    },
                    y: {
                        ticks: {
                            color: '#4a5568',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#edf2f7'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }
});
