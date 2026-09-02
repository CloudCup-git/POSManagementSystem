<?php
// ── HR REFERENCE DATA ─────────────────────────────────────────────
// Single source of truth for the Position / Department dropdowns used
// across the HR module (Employee Records AND Job Postings). Edit these
// arrays to match your actual staffing structure — every page that
// needs these lists includes this file instead of keeping its own copy,
// so the two modules never drift out of sync.

const HR_POSITIONS = [
    'Barista',
    'Cashier',
    'Shift Supervisor',
    'Kitchen Staff',
    'Baker',
    'Store Manager',
    'Assistant Manager',
    'Delivery Rider',
    'Inventory Staff',
    'Janitor / Utility',
];

const HR_DEPARTMENTS = [
    'Front of House',
    'Back of House',
    'Kitchen',
    'Management',
    'Delivery',
    'Inventory',
    'Maintenance',
];

// ── STANDARD BENEFITS ──────────────────────────────────────────────
// Applied to every position posted. Split into (1) statutory benefits,
// which are legally required for all rank-and-file employees in the
// Philippines regardless of employer size, and (2) supplemental perks
// commonly offered by small F&B/retail businesses to stay competitive
// for hiring. Edit this list to match what the business actually
// provides — it feeds every job posting from one place.
const HR_STANDARD_BENEFITS = [
    'statutory' => [
        'SSS (Social Security System) coverage',
        'PhilHealth coverage',
        'Pag-IBIG Fund membership',
        '13th month pay',
        'Service Incentive Leave (5 days/year)',
        'Holiday pay and night differential, as applicable',
    ],
    'supplemental' => [
        'Free meals or meal allowance on shift',
        'Free coffee/drinks while on duty',
        'Employee discount on off-shift purchases',
        'Flexible scheduling where possible',
        'Skills training / certification support (e.g. barista courses, food handler\'s certificate)',
    ],
];

// ── JOB POSTING TEMPLATES ─────────────────────────────────────────
// Default department, description, requirements, and monthly salary
// range for each HR_POSITIONS entry. Used by Job_Postings_Page.php to
// auto-fill the "Post a New Opening" form when a position is selected,
// so every posting starts from a consistent, researched baseline.
// Salary ranges are basic monthly gross pay (PHP), anchored to the
// CALABARZON minimum wage (Wage Order No. IVA-22, ₱508–₱600/day,
// ~26 paid days/month) and cross-checked against 2026 Jobstreet,
// Indeed PH, Glassdoor, and PayScale data for small F&B/retail
// businesses. Review periodically against current wage orders.
const HR_POSITION_TEMPLATES = [
    'Barista' => [
        'department'   => 'Front of House',
        'salary_min'   => 15600,
        'salary_max'   => 19000,
        'description'  => "Prepare and serve coffee, tea, and other beverages to recipe standard, operate the POS terminal, and keep the coffee station clean and stocked. Great for someone who enjoys fast-paced customer-facing work and takes pride in consistent drink quality.",
        'requirements' => [
            'High school graduate; barista/food & beverage NC II or short course an advantage but not required',
            'No experience required for entry level; 6 months of café or F&B experience preferred',
            'Basic espresso/coffee preparation and latte art',
            'Comfortable using a POS system and handling cash/card payments',
            'Willing to secure a food handler\'s / health certificate before start',
        ],
    ],
    'Cashier' => [
        'department'   => 'Front of House',
        'salary_min'   => 15600,
        'salary_max'   => 18500,
        'description'  => "Process customer orders and payments accurately, manage the cash drawer, and help resolve order concerns at the counter. Ideal for someone detail-oriented, comfortable with numbers, and friendly under pressure.",
        'requirements' => [
            'High school graduate; college-level units in a business-related course an advantage',
            'No experience required for entry level; prior retail or F&B cashiering preferred',
            'POS system operation and basic cash reconciliation',
            'Good basic arithmetic and attention to detail',
            'Willing to secure a food handler\'s / health certificate before start',
        ],
    ],
    'Shift Supervisor' => [
        'department'   => 'Front of House',
        'salary_min'   => 18000,
        'salary_max'   => 23000,
        'description'  => "Lead the crew during an assigned shift — oversee service quality, approve voids/discounts within authorized limits, and prepare shift-end sales and cash reports. A good fit for someone with prior F&B experience who's ready to take on team leadership.",
        'requirements' => [
            'High school graduate; college graduate or 2+ years college preferred',
            '1–2 years of F&B or retail experience, including at least 6 months in a lead or senior crew role',
            'Team leadership and shift scheduling',
            'POS reporting and cash/inventory reconciliation',
            'Strong conflict-resolution and customer-complaint handling skills',
        ],
    ],
    'Kitchen Staff' => [
        'department'   => 'Kitchen',
        'salary_min'   => 15600,
        'salary_max'   => 18000,
        'description'  => "Prepare ingredients and cook menu items to standard recipes, keep the kitchen clean and organized, and help monitor stock levels. Suited to someone who works well on their feet in a fast-paced kitchen environment.",
        'requirements' => [
            'High school graduate; vocational culinary training an advantage but not required',
            'No experience required for entry level; prior kitchen or food prep experience preferred',
            'Basic food preparation, portioning, and plating skills',
            'Understanding of food safety and sanitation practices',
            'Willing to secure a food handler\'s / health certificate before start',
        ],
    ],
    'Baker' => [
        'department'   => 'Kitchen',
        'salary_min'   => 17000,
        'salary_max'   => 21000,
        'description'  => "Bake bread, pastries, and other goods to the day's production schedule, monitor oven time/temperature for consistent quality, and keep baked goods properly labeled and rotated. Great for someone with a steady hand and an eye for consistency.",
        'requirements' => [
            'High school graduate; TESDA Bread and Pastry Production NC II or equivalent training preferred',
            '6 months–1 year of baking experience; entry-level applicants with formal training may be considered',
            'Knowledge of dough/batter preparation, proofing, and baking to spec',
            'Understanding of ingredient ratios and shelf-life/FIFO stock rotation',
            'Willing to secure a food handler\'s / health certificate before start',
        ],
    ],
    'Store Manager' => [
        'department'   => 'Management',
        'salary_min'   => 25000,
        'salary_max'   => 35000,
        'description'  => "Own overall store performance — operations, staffing, inventory, and cost control — and ensure the team delivers consistent service and compliance with food safety and permit requirements. Best suited to an experienced F&B or retail leader ready to run the business day to day.",
        'requirements' => [
            'Bachelor\'s degree, preferably in Business Administration, Hospitality Management, or a related field',
            '2–4 years of experience in F&B or retail operations, with at least 1 year in a managerial or store-in-charge role',
            'P&L management, budgeting, and sales reporting',
            'Inventory control, procurement, and supplier coordination',
            'Staff scheduling, hiring, and performance management',
        ],
    ],
    'Assistant Manager' => [
        'department'   => 'Management',
        'salary_min'   => 19000,
        'salary_max'   => 24000,
        'description'  => "Support the Store Manager with day-to-day operations — supervising shifts, checking inventory, and stepping in as officer-in-charge when needed. A good next step for a supervisor ready to grow into store management.",
        'requirements' => [
            'Bachelor\'s degree preferred; at least 2 years of college with relevant experience may be considered',
            '1–2 years of F&B or retail experience, including supervisory exposure',
            'Comfortable with scheduling, inventory checks, and opening/closing procedures',
            'Basic staff supervision, training, and customer-service recovery',
            'Working knowledge of POS and sales reporting',
        ],
    ],
    'Delivery Rider' => [
        'department'   => 'Delivery',
        'salary_min'   => 13500,
        'salary_max'   => 17000,
        'description'  => "Pick up and deliver customer orders within the service area, collect payment on cash-on-delivery orders, and keep food quality and packaging intact in transit. Base pay only — most postings should note any per-delivery or fuel allowance offered on top.",
        'requirements' => [
            'High school graduate',
            'Valid Philippine driver\'s license (LTO, appropriate motorcycle restriction)',
            'Own or company-provided motorcycle in good running condition',
            'Basic navigation / map app familiarity and route sense',
            'Clean, safe driving record',
        ],
    ],
    'Inventory Staff' => [
        'department'   => 'Inventory',
        'salary_min'   => 15600,
        'salary_max'   => 19000,
        'description'  => "Receive and check deliveries against purchase orders, run periodic stock counts, and keep the stockroom organized and accurately logged. Good fit for someone organized, accurate, and comfortable with basic record-keeping.",
        'requirements' => [
            'High school graduate; college-level units in a business or logistics-related course an advantage',
            'No experience required for entry level; prior warehouse or stockroom experience preferred',
            'Basic inventory/stock-count procedures and record-keeping',
            'Comfortable using the POS/inventory module for stock in-and-out',
            'Strong attention to detail and accuracy',
        ],
    ],
    'Janitor / Utility' => [
        'department'   => 'Maintenance',
        'salary_min'   => 13208,
        'salary_max'   => 15600,
        'description'  => "Keep the dining area, restrooms, and common areas clean and presentable, handle light maintenance, and assist other departments with general utility tasks as needed. A dependable, hands-on role at the heart of daily store upkeep.",
        'requirements' => [
            'High school graduate; elementary graduate may be considered depending on local requirements',
            'No experience required',
            'Basic cleaning, sanitation, and maintenance know-how',
            'Proper handling of cleaning chemicals and equipment',
            'Reliable, with the physical stamina for manual tasks',
        ],
    ],
];