Book 1

## **Enterprise Software Requirements Specification (SRS) v2.0**

# **BOOK 1 - PRODUCT FOUNDATION**

## **A.1 Purpose**

This glossary defines the standard business terminology used throughout the STEM Learning documentation.

Every stakeholder-including business analysts, designers, developers, QA engineers, support staff, and administrators-should use these definitions consistently.

## **A.2 Core Business Terms**

| **Term**          | **Definition**                                                                                                      |
| ----------------- | ------------------------------------------------------------------------------------------------------------------- |
| Student           | A registered learner using the platform to achieve educational goals.                                               |
| ---               | ---                                                                                                                 |
| Instructor        | A verified educator approved to conduct online lessons.                                                             |
| ---               | ---                                                                                                                 |
| Guest             | A visitor who has not yet registered or signed in.                                                                  |
| ---               | ---                                                                                                                 |
| Administrator     | A platform operator responsible for configuration, operations, and governance.                                      |
| ---               | ---                                                                                                                 |
| Lesson            | A scheduled one-to-one online learning session between a student and an instructor.                                 |
| ---               | ---                                                                                                                 |
| Demo Lesson       | A free introductory lesson allowing a student to evaluate an instructor before booking paid lessons.                |
| ---               | ---                                                                                                                 |
| Recurring Lesson  | A lesson schedule repeated according to a defined pattern, such as daily or weekly.                                 |
| ---               | ---                                                                                                                 |
| Learning Goal     | The educational objective defined by the student.                                                                   |
| ---               | ---                                                                                                                 |
| Learning Plan     | A structured plan that combines goals, instructor, lesson schedule, and progress.                                   |
| ---               | ---                                                                                                                 |
| Subject           | An academic discipline or area of study offered through the platform.                                               |
| ---               | ---                                                                                                                 |
| Subject Category  | A higher-level grouping of related subjects.                                                                        |
| ---               | ---                                                                                                                 |
| Education Level   | The academic stage for which a subject or lesson is intended.                                                       |
| ---               | ---                                                                                                                 |
| Teaching Resource | Learning material provided by an instructor, such as notes or PDF documents.                                        |
| ---               | ---                                                                                                                 |
| Homework          | Educational work assigned by an instructor after a lesson.                                                          |
| ---               | ---                                                                                                                 |
| Waitlist          | A queue of students requesting notification when an instructor becomes available.                                   |
| ---               | ---                                                                                                                 |
| Wallet            | A digital balance maintained in the student's billing currency for payments, refunds, and rewards.                  |
| ---               | ---                                                                                                                 |
| Settlement        | The transfer of earned funds from the platform to an instructor.                                                    |
| ---               | ---                                                                                                                 |
| Withdrawal        | An instructor request to receive available earnings.                                                                |
| ---               | ---                                                                                                                 |
| Referral          | A program rewarding students for introducing new paying students.                                                   |
| ---               | ---                                                                                                                 |
| Activity Timeline | A chronological history of actions associated with a user or business entity.                                       |
| ---               | ---                                                                                                                 |
| Audit Log         | A permanent record of significant platform events and administrative actions.                                       |
| ---               | ---                                                                                                                 |
| Vacation Mode     | A temporary state in which an instructor is unavailable for bookings without losing profile visibility or rankings. |
| ---               | ---                                                                                                                 |
| Feature Flag      | A configurable switch used to enable or disable functionality without software changes.                             |
| ---               | ---                                                                                                                 |

# **CHAPTER 1 - Document Control**

## **1.1 Document Information**

| **Field**       | **Value**                                            |
| --------------- | ---------------------------------------------------- |
| Document Title  | Enterprise Software Requirements Specification (SRS) |
| ---             | ---                                                  |
| Product Name    | STEM Learning                                        |
| ---             | ---                                                  |
| Product Type    | Global Online Learning Marketplace                   |
| ---             | ---                                                  |
| Current Version | 2.0                                                  |
| ---             | ---                                                  |
| Document Status | Draft                                                |
| ---             | ---                                                  |
| Prepared For    | STEM Learning                                        |
| ---             | ---                                                  |
| Prepared By     | Product Team                                         |
| ---             | ---                                                  |
| Classification  | Confidential                                         |
| ---             | ---                                                  |
| Language        | English                                              |
| ---             | ---                                                  |
| Initial Release | TBD                                                  |
| ---             | ---                                                  |
| Last Updated    | TBD                                                  |
| ---             | ---                                                  |

## **1.2 Purpose of this Document**

This Software Requirements Specification (SRS) defines the complete business, functional, and non-functional requirements for the STEM Learning platform.

The purpose of this document is to establish a single source of truth for all stakeholders involved in planning, designing, developing, testing, deploying, operating, and maintaining the platform.

Rather than serving only as a technical specification, this document acts as the official product blueprint for the entire platform. It captures business objectives, operational rules, user journeys, administrative workflows, system behavior, and future expansion strategies to ensure that every component of the platform is designed consistently and evolves in alignment with the product vision.

This document is intended to eliminate ambiguity during development, reduce implementation risks, simplify onboarding for new team members, and provide a long-term reference for product evolution.

## **1.3 Product Overview**

STEM Learning is an enterprise-grade online learning marketplace that connects students with qualified instructors through a secure, automated, and scalable booking platform.

Unlike traditional tutoring websites that primarily focus on scheduling lessons, STEM Learning is designed as a complete learning journey platform. It supports instructor discovery, free demo sessions, recurring lesson scheduling, progress tracking, homework management, secure payments, localized pricing, multi-country operations, and future AI-assisted learning experiences.

The platform is designed with automation as a core principle, minimizing manual administrative effort while providing students and instructors with a seamless and transparent experience.

Initially, the platform will support online one-to-one learning sessions delivered through integrated video meeting providers. Future releases will expand the platform with additional learning experiences, AI capabilities, and enterprise partnerships while maintaining compatibility with the original architecture.

## **1.4 Business Domain**

STEM Learning operates within the following business domains:

- Online Education
- One-to-One Learning
- Instructor Marketplace
- Learning Management
- Educational Technology (EdTech)
- Online Scheduling
- Digital Payments
- Student Progress Tracking

Future business domains include:

- Corporate Learning
- AI-Powered Education
- Learning Analytics
- Educational Content Marketplace

## **1.5 Project Vision**

The vision of STEM Learning is to become a globally trusted online learning platform where students can easily discover qualified instructors, schedule personalized learning sessions, and achieve measurable educational outcomes regardless of geographical location.

The platform aims to provide a premium learning experience while significantly reducing operational overhead through intelligent automation, configurable workflows, and enterprise-grade administrative tools.

Rather than functioning as a simple booking system, STEM Learning is envisioned as a lifelong learning ecosystem that supports learners throughout their educational journey.

## **1.6 Primary Goals**

The initial release of the platform is designed to achieve the following strategic goals:

- Deliver a reliable online one-to-one learning marketplace.
- Enable instructors to manage availability independently.
- Allow students to book free demo sessions and paid lessons with minimal effort.
- Support recurring lesson scheduling and long-term learning plans.
- Provide localized experiences through country-specific currencies, payment gateways, languages, and regional configurations.
- Minimize manual administration through configurable business rules and automation.
- Ensure financial transparency through secure wallet management, payment processing, and settlement workflows.
- Establish a scalable foundation capable of supporting future AI-powered educational services.

## **1.7 Scope of Version 1**

Version 1 focuses on building a production-ready marketplace with the following capabilities:

### **Public Platform**

- Public website
- SEO-optimized instructor profiles
- Subject discovery
- Learning information pages
- Blog
- CMS
- Contact and support pages

### **Student Platform**

- Registration and authentication
- Profile management
- Instructor discovery
- Free demo booking
- Paid lesson booking
- Recurring lessons
- Wallet
- Payments
- Homework
- Reviews
- Notifications
- Learning plans
- Favorite instructors
- Waitlist management

### **Instructor Platform**

- Registration
- KYC verification
- Availability management
- Teaching resources
- Lesson management
- Homework management
- Earnings
- Withdrawal requests
- Performance analytics
- Vacation mode

### **Administration**

- User management
- Instructor verification
- Country management
- Currency management
- Subject management
- Education level management
- Booking management
- Wallet management
- Payment management
- Global settings
- Reports
- Notifications
- CMS
- Audit logs
- Feature configuration

## **1.8 Out of Scope for Version 1**

The following capabilities are intentionally excluded from the initial release but are considered part of the long-term product roadmap:

- Native mobile applications
- Group classes
- Parent accounts
- Corporate learning
- School partnerships
- Subscription billing
- Lesson packages
- AI-generated lesson summaries
- AI homework evaluation
- AI tutor recommendations
- AI learning assistant
- Multi-tenant deployments
- Marketplace APIs for third parties

## **1.9 Guiding Principles**

The platform will be developed according to the following principles:

- Student-first experience
- Instructor empowerment
- Automation over manual operations
- Configuration over hardcoded business rules
- Security by default
- Scalability from day one
- Localization for global adoption
- Transparent financial operations
- Modular business domains within a single Laravel application
- Future-ready architecture for AI and platform expansion

## **1.10 Document Governance**

This SRS is a living document. Changes to business rules, workflows, or functional requirements should be reviewed, versioned, and approved before implementation. All future enhancements must remain consistent with the principles and objectives defined in this document unless an approved revision explicitly supersedes them.

#

#

#

#

#

#

#

#

# **CHAPTER 2 - INTRODUCTION**

# **2.1 Introduction**

The education industry is rapidly shifting from traditional classroom-based learning to personalized online learning experiences. Students increasingly seek qualified instructors who can provide one-to-one guidance tailored to their learning goals, schedules, and preferred pace. At the same time, experienced instructors require a reliable platform to reach students globally, manage their teaching schedules efficiently, and receive secure payments without administrative complexity.

While numerous tutoring platforms exist, many suffer from fragmented user experiences, limited automation, inconsistent scheduling, inadequate localization, poor instructor onboarding, and insufficient operational tools for administrators.

STEM Learning is designed to address these challenges by providing a modern, enterprise-grade online learning marketplace that combines instructor discovery, intelligent scheduling, secure payments, localized pricing, recurring learning plans, and scalable administrative tools into a single integrated platform.

Rather than functioning solely as a tutor booking website, STEM Learning is designed as a **Learning Journey Platform**, where students progress through structured educational experiences while instructors manage their teaching activities efficiently and administrators oversee the platform through configurable workflows and automation.

# **2.2 Purpose**

The primary purpose of STEM Learning is to provide a secure, scalable, and highly automated platform that connects students with qualified instructors for personalized online education.

The platform aims to:

- Simplify instructor discovery.
- Enable frictionless booking of free demo and paid lessons.
- Support recurring learning schedules.
- Reduce manual administrative work through automation.
- Provide transparent financial management for students and instructors.
- Deliver localized experiences based on country, currency, language, and timezone.
- Establish a future-ready foundation for AI-assisted education and long-term learning management.

The platform is intended to support learners from multiple countries while allowing administrators to manage business rules through configuration rather than software changes.

# **2.3 Vision Statement**

To become one of the world's most trusted online learning platforms by delivering personalized, accessible, and technology-driven education that connects students with exceptional instructors while empowering learning through automation, transparency, and innovation.

# **2.4 Mission Statement**

Our mission is to simplify online education by providing an intelligent platform where students can confidently discover expert instructors, schedule personalized lessons, achieve measurable learning outcomes, and continue lifelong learning through a seamless digital experience.

# **2.5 Product Positioning**

STEM Learning is positioned as a premium online learning marketplace focused on high-quality one-to-one instruction.

Unlike generic tutoring websites that primarily facilitate lesson bookings, STEM Learning emphasizes the complete educational lifecycle, including instructor discovery, learning plans, recurring sessions, homework management, progress tracking, and future AI-assisted learning support.

The platform prioritizes automation, scalability, and localization to support international growth while maintaining a consistent and secure user experience.

# **2.6 Problem Statement**

Students currently face several challenges when searching for online instructors:

- Difficulty identifying qualified and trustworthy instructors.
- Limited visibility into instructor expertise and teaching quality.
- Complicated scheduling across different time zones.
- Inconsistent payment experiences.
- Lack of structured long-term learning plans.
- Limited progress tracking after lessons.
- Poor communication between students and instructors.
- Fragmented learning resources across multiple platforms.

Similarly, instructors often encounter operational challenges:

- Difficulty attracting students.
- Managing schedules manually.
- Administrative overhead.
- Delayed or inconsistent payments.
- Limited performance insights.
- Difficulty managing recurring students.

Platform administrators also face challenges such as:

- Manual instructor verification.
- Booking conflicts.
- Payment disputes.
- Operational inefficiencies.
- Limited reporting.
- Difficulty scaling manual processes.

STEM Learning addresses these challenges through configurable automation, enterprise-grade workflows, and centralized platform management.

# **2.7 Solution Overview**

STEM Learning provides a centralized digital ecosystem where every stakeholder benefits from an optimized experience.

Students can:

- Search instructors using advanced filters.
- Book free demo lessons.
- Schedule paid one-time or recurring lessons.
- Recharge wallets.
- Receive reminders.
- Submit homework.
- Review instructors.
- Track their learning journey.

Instructors can:

- Apply through a structured verification process.
- Configure availability.
- Manage teaching schedules.
- Conduct online lessons.
- Assign homework.
- Monitor earnings.
- Request withdrawals.
- Track teaching performance.

Administrators can:

- Verify instructor applications.
- Configure global business rules.
- Manage countries and localization.
- Monitor financial operations.
- Generate reports.
- Control platform features.
- Observe live sessions for quality assurance.
- Scale operations through automation.

# **2.8 Product Philosophy**

The design and development of STEM Learning are guided by the following principles:

### **Automation First**

Business processes should be automated wherever possible to reduce operational overhead and improve user experience.

### **Student-Centric Experience**

Every feature should prioritize simplicity, transparency, and educational value for students.

### **Instructor Empowerment**

Instructors should have the tools needed to manage their teaching activities independently while maintaining platform quality standards.

### **Configuration Over Customization**

Business rules should be configurable through the administration panel rather than requiring software modifications.

### **Scalability**

Every component should be designed to support future expansion without significant architectural changes.

### **Localization**

Country-specific requirements such as currencies, payment gateways, languages, and regional preferences should be supported through configuration.

### **Security**

User privacy, financial data, and educational information must be protected using industry-standard security practices.

### **Continuous Improvement**

The platform should evolve continuously based on analytics, user feedback, and technological advancements.

# **2.9 Product Objectives**

The objectives for the initial release include:

### **Business Objectives**

- Launch a reliable online learning marketplace.
- Build trust among students and instructors.
- Minimize manual administrative operations.
- Achieve sustainable revenue growth.
- Support international expansion.

### **User Objectives**

Students should be able to:

- Discover instructors easily.
- Book lessons quickly.
- Learn consistently.
- Track progress.
- Manage payments securely.

Instructors should be able to:

- Manage availability.
- Deliver lessons.
- Monitor earnings.
- Grow their student base.

Administrators should be able to:

- Operate the platform efficiently.
- Configure business rules.
- Monitor performance.
- Ensure quality.
- Maintain compliance.

# **2.10 Product Scope**

The initial version of STEM Learning includes the following core capabilities:

### **Public Website**

- Home page
- About
- Subjects
- Public instructor profiles
- Blogs
- FAQs
- Contact
- SEO pages

### **Student Portal**

- Registration
- Authentication
- Instructor discovery
- Demo booking
- Paid booking
- Recurring lessons
- Wallet
- Homework
- Reviews
- Notifications
- Learning plans
- Favorite instructors
- Waitlist

### **Instructor Portal**

- Application
- KYC verification
- Availability management
- Teaching resources
- Lesson management
- Homework
- Earnings
- Withdrawals
- Vacation mode
- Performance analytics

### **Administrator Portal**

- Dashboard
- User management
- Instructor approval
- Subject management
- Education levels
- Country and currency management
- Payment configuration
- Wallet management
- Reports
- CMS
- Notifications
- Global settings
- Feature flags
- Audit logs

# **2.11 Assumptions**

The following assumptions apply to Version 1:

- All lessons are conducted online.
- Students have internet access and a compatible device.
- Instructors maintain their own availability.
- Payments are processed through supported payment gateways.
- Students select instructors directly or through marketplace recommendations.
- Free demo lessons are limited according to platform rules.
- All recurring bookings follow configured scheduling rules.
- Wallet transactions are recorded permanently.
- Business rules are managed through the administration panel.

# **2.12 Constraints**

Version 1 intentionally excludes:

- Native mobile applications.
- Offline or in-person classes.
- Parent accounts.
- Organization or school accounts.
- Lesson package subscriptions.
- AI-powered educational features.
- Third-party public APIs.
- Multi-tenant deployments.

These features remain part of the long-term roadmap.

# **2.13 Definition of Success**

The first production release will be considered successful when it achieves the following:

### **Operational Success**

- Minimal manual intervention for bookings and scheduling.
- Reliable instructor onboarding.
- Stable payment processing.
- Accurate recurring lesson management.
- Automated notifications.

### **User Success**

- Students can complete a booking within a few minutes.
- Instructors can manage schedules without administrative assistance.
- Positive user satisfaction and retention.

### **Business Success**

- Sustainable instructor acquisition.
- Increasing recurring bookings.
- Healthy wallet usage.
- Strong demo-to-paid conversion rates.
- Scalable operations across multiple countries.

#

#

# **CHAPTER 3 - BUSINESS MODEL & MARKETPLACE STRATEGY**

# **3.1 Business Overview**

STEM Learning is an enterprise-grade online learning marketplace that enables students to connect with qualified instructors for personalized one-to-one online learning sessions.

Unlike conventional tutoring websites that focus only on lesson booking, STEM Learning is designed as a complete educational ecosystem where students can define learning goals, discover instructors, attend structured lessons, complete homework, monitor progress, and continue long-term learning journeys.

The platform serves as a trusted intermediary between students and instructors by managing scheduling, payments, communication, instructor verification, and operational workflows.

The long-term objective is to become a globally recognized digital education platform supporting learners from different countries through localized experiences while maintaining a consistent platform architecture.

# **3.2 Marketplace Model**

STEM Learning operates as a **Managed Instructor Marketplace**.

Unlike open marketplaces where instructors independently define pricing, communicate directly with students, and control commercial relationships, STEM Learning maintains centralized control over the business relationship while giving instructors operational independence for teaching activities.

The platform is responsible for:

- Student acquisition.
- Instructor onboarding.
- Instructor verification.
- Instructor ranking.
- Localized pricing.
- Payment collection.
- Wallet management.
- Lesson scheduling.
- Meeting creation.
- Notifications.
- Financial settlements.
- Quality assurance.
- Platform policies.

Instructors focus on:

- Delivering high-quality lessons.
- Maintaining availability.
- Completing scheduled classes.
- Assigning homework.
- Supporting student learning.
- Improving teaching performance.

Students focus on:

- Defining learning goals.
- Selecting instructors.
- Booking lessons.
- Completing homework.
- Tracking progress.
- Providing feedback.

This separation of responsibilities creates a scalable marketplace where operational consistency is maintained by the platform while educational quality is delivered by instructors.

# **3.3 Marketplace Participants**

The marketplace consists of four primary participants.

## **Guest**

Guests are visitors who browse the public website before creating an account.

Guests can:

- View subjects.
- Search public instructor profiles.
- Read blogs and educational content.
- Explore pricing information.
- Learn about the platform.
- Register as students.
- Apply to become instructors.

Guests cannot:

- Book lessons.
- Contact instructors.
- Access learning resources.
- View private schedules.
- Join online meetings.

## **Student**

Students are registered users seeking personalized learning experiences.

Students are the primary customers of the platform.

Students can:

- Maintain a learning profile.
- Search instructors.
- Filter instructors.
- Favorite instructors.
- Book free demo lessons.
- Book paid lessons.
- Schedule recurring lessons.
- Manage wallets.
- Recharge balances.
- Complete homework.
- Submit reviews.
- Join online lessons.
- Follow learning plans.
- Receive reminders.

Students do not negotiate pricing directly with instructors.

All commercial transactions are managed by the platform.

## **Instructor**

Instructors are verified professionals delivering online educational services.

Every instructor must successfully complete the platform's verification process before becoming active.

Instructors are responsible for:

- Maintaining profiles.
- Managing availability.
- Conducting lessons.
- Uploading teaching resources.
- Assigning homework.
- Monitoring student progress.
- Requesting withdrawals.
- Maintaining teaching quality.

Instructors cannot:

- Set student-facing pricing.
- Collect payments directly.
- Exchange payment information with students.
- Circumvent platform policies.

## **Administrator**

Administrators manage the platform's operational, financial, and quality assurance processes.

Administrators control:

- Platform settings.
- Countries.
- Currencies.
- Subjects.
- Education levels.
- Instructor verification.
- Instructor payouts.
- Learning policies.
- Booking rules.
- Notifications.
- Reports.
- Marketing configuration.
- Feature availability.
- Platform security.

Administrators have complete operational oversight while minimizing manual intervention through configurable workflows.

# **3.4 Core Business Philosophy**

The business is designed around the following principles.

## **Learning First**

The objective is not simply to sell lessons but to help students achieve measurable learning outcomes.

Every feature should contribute toward improving educational success.

## **Automation First**

Manual administrative work should be minimized wherever possible.

Examples include:

- Automatic scheduling.
- Meeting generation.
- Reminder notifications.
- Wallet deductions.
- Settlement calculations.
- Waitlist notifications.
- Financial reporting.

## **Instructor Empowerment**

Instructors manage educational delivery while the platform manages business operations.

This allows instructors to focus on teaching rather than administrative tasks.

## **Student Trust**

Students should always experience:

- Transparent pricing.
- Verified instructors.
- Secure payments.
- Reliable scheduling.
- Quality assurance.
- Structured learning.

## **Long-Term Learning**

The platform encourages continuous learning through:

- Recurring lessons.
- Learning goals.
- Homework.
- Progress tracking.
- Instructor continuity.
- Future AI recommendations.

# **3.5 Learning Journey Philosophy**

Unlike conventional tutoring platforms where every booking is treated as an isolated transaction, STEM Learning considers each lesson part of a continuous educational journey.

Every student interaction contributes to a structured learning lifecycle.

The typical learning journey consists of:

- Student identifies a learning goal.
- Student discovers suitable instructors.
- Student books a free demo lesson.
- Student evaluates the instructor.
- Student begins recurring lessons.
- Instructor assigns homework.
- Student completes learning milestones.
- Student reviews progress.
- Student continues with advanced learning goals.

This approach improves student retention while encouraging stronger instructor-student relationships.

# **3.6 Platform-Controlled Commercial Model**

One of the defining characteristics of STEM Learning is that the platform owns the commercial relationship.

Students purchase educational services from STEM Learning rather than directly from instructors.

The platform determines:

- Student pricing.
- Promotional campaigns.
- Regional pricing.
- Discounts.
- Wallet policies.
- Referral rewards.
- Instructor compensation.
- Incentive programs.

Instructors do not publish public lesson prices.

Instead, instructors receive compensation according to platform-defined policies based on experience, expertise, quality, and internal business strategies.

This model provides several advantages:

- Consistent pricing.
- Better revenue management.
- Flexible marketing.
- Country-specific pricing.
- Dynamic instructor compensation.
- Protection against off-platform negotiations.

# **3.7 Revenue Strategy**

The initial business model focuses on educational service revenue.

Primary revenue sources include:

- Paid one-to-one lessons.
- Wallet recharges.
- Platform service margin.
- Future premium offerings.

Future revenue opportunities include:

- Lesson packages.
- Corporate learning.
- AI-powered learning services.
- Featured instructor promotions.
- Subscription plans.

The business model intentionally avoids exposing internal instructor compensation to students.

# **3.8 Instructor Compensation Strategy**

Instructor compensation is managed internally by the platform.

Compensation may depend on:

- Experience.
- Qualifications.
- Performance.
- Student retention.
- Lesson completion.
- Internal rating.
- Promotional campaigns.

The platform reserves the right to revise instructor compensation independently of student pricing.

This allows sustainable business growth while maintaining competitive educational pricing.

# **3.9 Incentive Strategy**

Instead of commission-based earnings, instructors may receive configurable performance incentives.

Examples include:

- Demo-to-paid conversion bonuses.
- Student retention incentives.
- High completion rate rewards.
- Seasonal campaigns.
- Quality performance bonuses.

All incentive rules are configurable through the administration panel.

# **3.10 Referral Strategy**

The platform supports a configurable student referral program.

Students may receive wallet credits when referred users complete eligible paid lessons.

Referral campaigns can define:

- Reward type (fixed or percentage).
- Eligible lesson types.
- Maximum rewarded lessons.
- Campaign duration.
- Geographic applicability.
- Minimum purchase conditions.

Referral rewards are credited to student wallets and may be subject to expiration policies configured by administrators.

# **3.11 Marketplace Growth Strategy**

Growth will be driven through multiple channels:

- Search engine optimization.
- Public instructor profiles.
- Educational content.
- Student referrals.
- Social media marketing.
- Country-specific campaigns.
- Instructor quality improvements.
- Repeat learning plans.

Future growth initiatives include AI-assisted instructor recommendations, educational partnerships, and localized marketing strategies.

# **3.12 Competitive Differentiators**

STEM Learning differentiates itself through:

- Verified instructor onboarding.
- Platform-controlled commercial model.
- Country-aware pricing and localization.
- Free instructor-specific demo lessons.
- Intelligent recurring scheduling.
- Wallet-based recurring payments.
- Learning plans rather than isolated bookings.
- Waitlist functionality.
- Homework management.
- Progress tracking.
- Configurable business rules.
- Enterprise-grade administration.
- Future AI integration.

# **3.13 Long-Term Product Vision**

The long-term vision is to evolve from an online tutoring marketplace into a comprehensive learning platform supporting students throughout their educational lifecycle.

Future capabilities include:

- AI-generated lesson summaries.
- AI-assisted homework review.
- Personalized instructor recommendations.
- Learning analytics.
- Corporate learning solutions.
- Educational institutions.
- Lesson packages.
- Mobile applications.
- International expansion.

#

# **CHAPTER 4 - PRODUCT SCOPE, BUSINESS DOMAINS & PLATFORM MODULES**

# **4.1 Introduction**

STEM Learning is designed as an enterprise-grade online learning marketplace composed of multiple interconnected business domains.

Each domain represents a distinct business capability with clearly defined responsibilities, workflows, ownership, and configuration.

Although all domains operate within a single Laravel application, they remain logically separated to simplify maintenance, improve scalability, and support future platform expansion.

The platform follows a **domain-driven monolith** approach, where business logic is organized by responsibility rather than by technology or infrastructure.

# **4.2 Product Scope**

The initial release focuses on delivering a complete online learning ecosystem for one-to-one education.

The platform enables:

- Student onboarding
- Instructor onboarding
- Instructor verification
- Instructor discovery
- Learning goal management
- Free demo lessons
- Paid lessons
- Recurring learning schedules
- Wallet-based payments
- Instructor settlements
- Homework management
- Learning progress
- Reviews
- Notifications
- Localized pricing
- Multi-country operations
- Administrative automation

The objective is to eliminate manual operations while maintaining flexibility through configurable business rules.

# **4.3 Business Domain Architecture**

The platform is organized into the following business domains.

## **Domain 1 - Identity & Access**

### **Purpose**

Manage authentication, authorization, and account security.

### **Includes**

- Registration
- Login
- Password recovery
- Email verification
- Two-factor authentication
- Session management
- User roles
- Permissions
- Security logs

### **Users**

- Guest
- Student
- Instructor
- Administrator

## **Domain 2 - Student Management**

### **Purpose**

Manage the complete lifecycle of students.

### **Includes**

- Registration
- Student profile
- Preferences
- Learning goals
- Favorite instructors
- Booking history
- Homework
- Wallet
- Notifications
- Learning analytics

## **Domain 3 - Instructor Management**

### **Purpose**

Manage instructor onboarding, verification, teaching operations, and performance.

### **Includes**

- Instructor application
- KYC verification
- Profile
- Subjects
- Languages
- Availability
- Vacation mode
- Teaching resources
- Homework
- Earnings
- Withdrawals
- Analytics

## **Domain 4 - Learning Catalog**

### **Purpose**

Manage educational content and learning structure.

### **Includes**

- Subject hierarchy
- Education levels
- Subject categories
- Learning roadmap
- Teaching languages
- Difficulty levels
- Future certifications

## **Domain 5 - Learning Journey**

### **Purpose**

Support long-term student learning rather than isolated lessons.

### **Includes**

- Learning goals
- Instructor recommendations
- Learning roadmap
- Homework
- Progress tracking
- Future AI insights

## **Domain 6 - Instructor Marketplace**

### **Purpose**

Connect students with qualified instructors.

### **Includes**

- Instructor search
- Advanced filters
- Public instructor profiles
- SEO
- Favorites
- Waitlist
- Recommendations
- Future AI matching

## **Domain 7 - Availability Engine**

### **Purpose**

Provide intelligent scheduling while preventing conflicts.

### **Includes**

- Weekly schedule
- Working hours
- Breaks
- Vacation
- Public holidays
- Blocked slots
- Buffer time
- Booking notice period
- Maximum booking window
- Waitlist support

## **Domain 8 - Booking Engine**

### **Purpose**

Manage the complete lesson booking lifecycle.

### **Includes**

- Free demo booking
- Paid booking
- Single lesson
- Recurring lessons
- Daily recurrence
- Weekly recurrence
- Calendar integration
- Attendance
- Completion
- Cancellation
- Rescheduling
- No-show handling

## **Domain 9 - Meeting Management**

### **Purpose**

Manage online lesson delivery.

### **Includes**

- Meeting creation
- Meeting links
- Recording
- Attendance
- Admin observation
- Future recording management
- Meeting history

## **Domain 10 - Wallet & Financial Management**

### **Purpose**

Manage all financial transactions.

### **Includes**

- Student wallet
- Recharge
- Refund
- Referral rewards
- Wallet history
- Instructor earnings
- Instructor settlement
- Withdrawal requests
- Financial ledger

## **Domain 11 - Payment Processing**

### **Purpose**

Manage payment collection.

### **Includes**

- Payment gateways
- Payment verification
- Failed payments
- Payment history
- Invoices
- Regional payment routing

## **Domain 12 - Country & Localization**

### **Purpose**

Provide localized user experiences.

### **Includes**

- Countries
- Currencies
- Languages
- Timezones
- Date formats
- Country pricing
- Payment gateways
- Regional settings

## **Domain 13 - Communication**

### **Purpose**

Provide automated communication.

### **Includes**

- Email
- SMS
- WhatsApp
- In-app notifications
- Reminder engine
- Waitlist alerts
- Homework reminders

## **Domain 14 - Reviews & Ratings**

### **Purpose**

Measure teaching quality.

### **Includes**

- Lesson reviews
- Instructor ratings
- Moderation
- Review analytics

## **Domain 15 - Referral System**

### **Purpose**

Drive student acquisition.

### **Includes**

- Referral campaigns
- Wallet rewards
- Reward tracking
- Campaign configuration

## **Domain 16 - Content Management**

### **Purpose**

Manage website content.

### **Includes**

- Pages
- Blogs
- FAQs
- Testimonials
- Contact pages
- SEO
- Media

## **Domain 17 - Reporting & Analytics**

### **Purpose**

Provide business intelligence.

### **Includes**

- Revenue reports
- Booking reports
- Student analytics
- Instructor analytics
- Country analytics
- Wallet reports
- KPI dashboards

## **Domain 18 - Platform Administration**

### **Purpose**

Configure business rules and platform behavior.

### **Includes**

- Global settings
- Feature flags
- Countries
- Subjects
- Education levels
- Policies
- Notification templates
- Payment configuration
- Audit logs

# **4.4 Module Classification**

To simplify implementation, modules are grouped into functional categories.

### **Core Platform**

- Authentication
- Users
- Roles
- Security

### **Learning**

- Students
- Instructors
- Learning Goals
- Homework
- Subject Catalog

### **Marketplace**

- Instructor Discovery
- Public Profiles
- Favorites
- Waitlist

### **Scheduling**

- Availability
- Booking
- Meetings

### **Finance**

- Wallet
- Payments
- Withdrawals
- Settlements
- Referral Rewards

### **Communication**

- Email
- SMS
- WhatsApp
- Notifications

### **Content**

- CMS
- Blog
- SEO

### **Administration**

- Reports
- Settings
- Feature Flags
- Audit Logs

# **4.5 Product Boundaries**

### **Included in Version 1**

- Online learning only
- One-to-one lessons
- Instructor marketplace
- Student wallets
- Free demo lessons
- Paid lessons
- Recurring bookings
- Country-aware pricing
- Multi-currency
- Homework
- Reviews
- SEO-ready instructor profiles
- Waitlists
- Learning plans

### **Planned for Future**

- Mobile applications
- AI tutor recommendations
- AI lesson summaries
- AI homework review
- Lesson packages
- Subscription billing
- Parent accounts
- Organization accounts
- Group classes
- White-label deployments

# **4.6 Module Dependencies**

The domains are interconnected but loosely coupled.

Examples include:

- Booking depends on Availability, Payments, Meetings, and Notifications.
- Learning Journey depends on Students, Instructors, Homework, and Reviews.
- Wallet depends on Payments, Referrals, and Settlements.
- Instructor Marketplace depends on Public Profiles, Subjects, Education Levels, and Availability.
- Reporting aggregates information from all business domains.

Each domain should expose well-defined business services while avoiding unnecessary dependencies.

# **4.7 Future Extensibility**

The architecture is intentionally designed to support future enhancements without major redesign.

Examples include:

- AI-powered learning services.
- Subscription billing.
- Lesson packages.
- Mobile applications.
- Corporate learning.
- Educational institutions.
- Additional payment gateways.
- New countries and currencies.
- Third-party integrations.

Future features should integrate with existing business domains rather than introducing duplicate concepts.

# **4.8 Domain Ownership Principles**

Each business domain is responsible for:

- Its own business rules.
- Validation.
- Configuration.
- Reporting.
- Notifications.
- Lifecycle management.

No domain should directly manipulate another domain's internal business logic. Cross-domain interactions should occur through defined business services and events to maintain clear boundaries and long-term maintainability.

# **4.9 Product Scope Summary**

Version 1 of STEM Learning delivers a complete marketplace for online one-to-one learning with enterprise-level operational capabilities. The platform balances automation, localization, financial management, and educational workflows while establishing a scalable foundation for future AI-powered and enterprise learning features.

# **CHAPTER 5 - STAKEHOLDERS, USER PERSONAS & USER JOURNEYS**

# **5.1 Introduction**

STEM Learning is a multi-sided online learning marketplace where different stakeholders interact to deliver a complete educational experience.

Each stakeholder has unique responsibilities, permissions, expectations, and business objectives. Understanding these differences is essential for designing workflows, interfaces, permissions, notifications, and operational policies.

This chapter defines the primary stakeholders, their personas, goals, challenges, permissions, and end-to-end journeys throughout the platform.

# **5.2 Stakeholder Overview**

The platform currently consists of four primary stakeholder groups:

| **Stakeholder** | **Primary Role**  | **Primary Objective**                        |
| --------------- | ----------------- | -------------------------------------------- |
| Guest           | Visitor           | Discover the platform and register           |
| ---             | ---               | ---                                          |
| Student         | Learner           | Find instructors and achieve learning goals  |
| ---             | ---               | ---                                          |
| Instructor      | Educator          | Deliver high-quality lessons and earn income |
| ---             | ---               | ---                                          |
| Administrator   | Platform Operator | Operate, configure, and grow the platform    |
| ---             | ---               | ---                                          |

Future stakeholder groups may include:

- Support Agents
- Finance Team
- Marketing Team
- Corporate Clients
- Educational Institutions

# **5.3 Guest Persona**

## **Description**

A guest is an unauthenticated visitor exploring the platform before creating an account.

Guests typically arrive through search engines, advertisements, referrals, or social media campaigns.

## **Goals**

Guests want to:

- Understand the platform.
- Explore available subjects.
- View instructor profiles.
- Compare instructors.
- Learn about pricing.
- Build confidence in the platform.
- Register as a student.
- Apply to become an instructor.

## **Pain Points**

Guests often worry about:

- Instructor quality.
- Pricing transparency.
- Trustworthiness.
- Learning outcomes.
- Platform reliability.

## **Platform Responsibilities**

The platform should help guests by providing:

- Fast loading pages.
- Clear messaging.
- SEO-friendly instructor profiles.
- Frequently Asked Questions.
- Success stories.
- Testimonials.
- Educational blog content.
- Clear registration process.

## **Permissions**

Guests may:

- Browse public pages.
- Search instructors.
- View public instructor profiles.
- Read blogs.
- Submit contact forms.
- Register.
- Apply as instructors.

Guests cannot:

- Book lessons.
- Contact instructors.
- View schedules.
- Join meetings.
- Access dashboards.

# **5.4 Student Persona**

## **Description**

A student is a registered learner seeking personalized education through online one-to-one lessons.

Students represent the primary customers of the platform.

## **Student Types**

The platform should support different learning needs, including:

- School students.
- College students.
- University students.
- Working professionals.
- Lifelong learners.
- Competitive exam candidates.
- Skill development learners.

## **Student Goals**

Students want to:

- Improve knowledge.
- Prepare for examinations.
- Develop professional skills.
- Learn flexibly.
- Find trusted instructors.
- Book recurring lessons.
- Track educational progress.

## **Student Pain Points**

Students frequently experience:

- Difficulty finding suitable instructors.
- Scheduling conflicts.
- Timezone confusion.
- Payment friction.
- Inconsistent lesson quality.
- Lack of long-term learning structure.

## **Platform Responsibilities**

The platform should:

- Recommend suitable instructors.
- Display localized pricing.
- Handle scheduling automatically.
- Send reminders.
- Manage recurring lessons.
- Store homework.
- Track learning progress.
- Provide secure payments.

## **Student Permissions**

Students may:

- Manage profile.
- Set learning goals.
- Search instructors.
- Filter instructors.
- Favorite instructors.
- Book free demos.
- Book paid lessons.
- Schedule recurring lessons.
- Recharge wallets.
- Join meetings.
- Submit homework.
- Download invoices.
- Submit reviews.
- Join waitlists.

Students cannot:

- Modify instructor profiles.
- Access platform settings.
- Approve instructors.
- Manage payments.

# **5.5 Instructor Persona**

## **Description**

An instructor is a verified education professional providing online learning services through STEM Learning.

Only approved instructors may conduct lessons.

## **Instructor Categories**

Examples include:

- Freelance instructor.
- School teacher.
- College lecturer.
- University professor.
- Industry expert.
- Professional trainer.

Version 1 supports freelance instructors.

## **Instructor Goals**

Instructors want to:

- Teach students.
- Build professional reputation.
- Receive recurring bookings.
- Earn consistent income.
- Manage schedules efficiently.
- Reduce administrative effort.
- Track performance.

## **Instructor Pain Points**

Common challenges include:

- Finding students.
- Managing calendars.
- Collecting payments.
- Administrative overhead.
- Student retention.
- Schedule conflicts.

## **Platform Responsibilities**

The platform should:

- Provide verified student bookings.
- Handle payments.
- Create meetings automatically.
- Manage reminders.
- Track earnings.
- Support withdrawals.
- Display analytics.

## **Instructor Permissions**

Approved instructors may:

- Update profile.
- Manage availability.
- Configure vacation mode.
- Upload teaching resources.
- Conduct lessons.
- Assign homework.
- View student bookings.
- Request withdrawals.
- View analytics.

Instructors cannot:

- Modify pricing.
- Collect payments directly.
- Change platform settings.
- Approve users.

# **5.6 Administrator Persona**

## **Description**

Administrators are responsible for managing the operational, financial, educational, and technical aspects of the platform.

The administrator ensures consistent platform quality while minimizing manual intervention through configurable business rules.

## **Administrator Goals**

Administrators aim to:

- Grow the platform.
- Maintain instructor quality.
- Ensure secure financial operations.
- Improve student satisfaction.
- Monitor business performance.
- Configure platform behavior.

## **Administrator Responsibilities**

Administrators manage:

- Instructor applications.
- KYC verification.
- Countries.
- Currencies.
- Subjects.
- Education levels.
- Wallets.
- Payments.
- Reports.
- CMS.
- Global settings.
- Notifications.
- Feature flags.
- Audit logs.

## **Administrator Permissions**

Administrators have full platform access, including:

- Configuration.
- Financial management.
- Reporting.
- User management.
- Quality assurance.
- Platform monitoring.

# **5.7 Student Journey**

The student journey is designed around achieving learning outcomes rather than simply booking lessons.

### **Stage 1 - Discovery**

- Visit platform.
- Explore subjects.
- Search instructors.
- Read instructor profiles.

### **Stage 2 - Registration**

- Create account.
- Verify email.
- Complete profile.
- Select country.
- Set learning goals.

### **Stage 3 - Instructor Selection**

- Filter instructors.
- Compare profiles.
- Favorite instructors.
- View availability.
- Select preferred instructor.

### **Stage 4 - Demo Lesson**

- Book free demo.
- Attend lesson.
- Evaluate teaching style.

### **Stage 5 - Paid Learning**

- Select lesson schedule.
- Choose recurring or single lessons.
- Complete payment.
- Attend lessons.

### **Stage 6 - Learning**

- Receive homework.
- Complete assignments.
- Track progress.
- Continue recurring learning.

### **Stage 7 - Retention**

- Submit reviews.
- Continue learning.
- Change instructors if needed.
- Pursue new learning goals.

# **5.8 Instructor Journey**

### **Stage 1 - Registration**

- Create account.
- Verify email.

### **Stage 2 - Application**

- Complete profile.
- Upload KYC documents.
- Submit application.

### **Stage 3 - Verification**

- Admin reviews application.
- Documents verified.
- Optional interview.
- Approval.

### **Stage 4 - Setup**

- Configure availability.
- Add subjects.
- Add education levels.
- Select teaching languages.
- Upload teaching resources.

### **Stage 5 - Teaching**

- Receive bookings.
- Conduct demo lessons.
- Conduct paid lessons.
- Assign homework.

### **Stage 6 - Growth**

- Improve ratings.
- Increase recurring students.
- Earn incentives.
- Request withdrawals.

# **5.9 Administrator Journey**

### **Daily Operations**

- Review dashboard.
- Verify instructor applications.
- Monitor bookings.
- Observe live classes when required.
- Review reports.
- Monitor financial activity.
- Configure business rules.

### **Weekly Operations**

- Settlement processing.
- Performance reviews.
- Marketing campaigns.
- Platform analytics.
- Instructor quality review.

### **Monthly Operations**

- Revenue analysis.
- Country performance.
- Instructor performance.
- Student retention.
- Feature adoption.
- Business planning.

# **5.10 Stakeholder Relationships**

The platform manages interactions between stakeholders rather than allowing unrestricted direct relationships.

### **Student ↔ Instructor**

Interactions include:

- Booking.
- Lessons.
- Homework.
- Reviews.
- Post-booking chat.

Communication is available only after a paid or demo booking has been confirmed.

### **Student ↔ Administrator**

Interactions include:

- Platform support.
- Payment issues.
- Account management.
- General assistance.

### **Instructor ↔ Administrator**

Interactions include:

- Verification.
- Withdrawals.
- Performance.
- Compliance.
- Platform updates.

# **5.11 User Experience Principles**

Every user experience should follow these principles:

- Simple onboarding.
- Minimal clicks.
- Transparent workflows.
- Fast performance.
- Consistent design.
- Mobile-responsive web experience.
- Clear feedback for every action.
- Accessible interfaces.
- Localized content and currency.
- Secure operations.

# **5.12 Success Criteria by Stakeholder**

### **Guest**

- Easily understands the platform.
- Completes registration.

### **Student**

- Finds a suitable instructor quickly.
- Books lessons without confusion.
- Continues recurring learning.

### **Instructor**

- Receives qualified bookings.
- Manages teaching efficiently.
- Earns consistent income.

### **Administrator**

- Operates the platform with minimal manual effort.
- Maintains quality.
- Scales operations effectively.

# **CHAPTER 6 - BUSINESS RULES, POLICIES & PLATFORM GOVERNANCE**

# **6.1 Introduction**

This chapter defines the global business rules, operational policies, and governance principles that apply across the entire STEM Learning platform.

These rules establish a consistent framework for how users interact with the platform, how business operations are performed, and how automated workflows behave.

All functional modules described in later volumes must comply with these policies unless an explicit exception is documented and approved.

The primary objectives of these policies are to:

- Ensure fairness between students and instructors.
- Maintain platform quality.
- Reduce manual administrative effort.
- Protect financial integrity.
- Provide predictable platform behavior.
- Support international scalability.

# **6.2 Platform Governance Principles**

Every feature developed for STEM Learning shall follow these principles.

## **Learning First**

The primary purpose of the platform is to help students achieve measurable learning outcomes rather than simply facilitate lesson bookings.

Every new feature should improve the educational experience.

## **Automation First**

Business operations should be automated whenever possible.

Examples include:

- Booking confirmation
- Meeting creation
- Calendar updates
- Reminder notifications
- Wallet deductions
- Referral rewards
- Settlement calculations
- Waitlist notifications

Administrative intervention should only be required when automation cannot safely resolve an issue.

## **Configuration Over Hardcoding**

Business rules must be configurable through the administration panel wherever practical.

Examples include:

- Demo duration
- Cancellation window
- Settlement period
- Referral campaigns
- Wallet limits
- Reminder schedules
- Feature availability by country

## **Transparency**

Students, instructors, and administrators should always have clear visibility into actions that affect them.

Examples include:

- Booking status
- Payment status
- Wallet transactions
- Withdrawal requests
- Lesson completion
- Homework status

## **Data Integrity**

Historical records shall not be silently modified or deleted.

Financial and educational history must remain auditable.

# **6.3 Account Policies**

## **Guest Accounts**

Guests may browse public content but cannot access protected platform features.

## **Student Accounts**

Students must:

- Register with a valid email address.
- Verify their email before booking lessons.
- Maintain an active account.
- Select a billing country during registration.

A student account may later apply to become an instructor through the instructor onboarding process.

## **Instructor Accounts**

Instructor access requires:

- Registration
- Email verification
- Complete application
- Required KYC documents
- Administrative approval

Only approved instructors may teach lessons.

## **Administrator Accounts**

Administrator access is restricted to authorized platform personnel.

Administrative permissions are role-based and follow the principle of least privilege.

# **6.4 Instructor Onboarding Policy**

Every instructor must complete the onboarding workflow before becoming active.

The workflow consists of:

- Registration
- Email verification
- Profile completion
- Subject selection
- Education level selection
- Language selection
- Document submission
- KYC verification
- Administrative review
- Approval
- Platform activation

Required verification documents are configurable by administrators.

Examples include:

- Government-issued identity document
- Profile photograph
- Address proof
- Highest educational qualification
- Teaching certification (optional)
- Resume
- Introduction video

Administrators may request additional documentation before approval.

# **6.5 Instructor Status Lifecycle**

Every instructor progresses through defined operational states.

Typical lifecycle:

- Draft
- Submitted
- Under Review
- Documents Pending
- Interview Required (optional)
- Approved
- Active
- Vacation
- Suspended
- Archived

Status changes are recorded in the activity history.

# **6.6 Booking Policies**

The booking engine follows these global rules:

- Students choose their preferred instructor.
- Students may book one free demo per instructor.
- Paid lessons require successful payment or sufficient wallet balance.
- Double booking is prohibited.
- Availability conflicts are prevented automatically.
- All bookings are timezone-aware.
- Every booking receives a unique reference number.
- Every booking has a complete lifecycle history.

Recurring bookings are supported for:

- Daily schedules
- Weekly schedules

Future versions may support lesson packages and subscriptions.

# **6.7 Demo Lesson Policy**

Demo lessons are intended to help students evaluate an instructor before committing to paid learning.

Rules:

- Demo lessons are free.
- Students select the instructor.
- One free demo per instructor per student.
- Demo duration is configurable globally.
- Demo lessons reserve instructor availability.
- Demo lessons generate meeting links automatically.
- Demo attendance contributes to instructor analytics.
- Students may continue with paid lessons using the same or a new schedule.

# **6.8 Cancellation Policy**

Students may cancel bookings according to configurable platform rules.

General policy:

- Eligible refunds are credited to the student wallet only.
- Original payment methods are not used for Version 1 refunds.
- Cancellation windows are configurable.
- Wallet refunds are recorded as immutable transactions.
- Administrators may override cancellation decisions when necessary.

# **6.9 Rescheduling Policy**

Students may request lesson rescheduling.

Platform behavior:

- Maximum reschedules are configurable.
- Rescheduling depends on instructor availability.
- Recurring lessons preserve the remaining schedule unless explicitly modified.
- Rescheduled lessons generate updated reminders and meeting information.

# **6.10 No-Show Policy**

Attendance is monitored using meeting participation data where available.

### **Student No-Show**

If the student does not join within the configured grace period:

- Lesson is marked as Student No-Show.
- Refund eligibility follows the configured cancellation policy.
- Instructor compensation follows platform policy.

### **Instructor No-Show**

If the instructor does not join within the configured grace period:

- Student receives a wallet refund.
- Instructor attendance metrics are updated.
- Administrators are notified.
- Repeated incidents may affect instructor status.

### **Both Absent**

If neither participant joins:

- Lesson is marked as Missed.
- Platform policy determines refund or rescheduling.

### **Technical Issues**

Participants may report technical problems within a configurable period after the lesson.

Administrators may review exceptional cases when automated resolution is not possible.

# **6.11 Availability Policy**

Instructors are responsible for maintaining accurate availability.

Availability includes:

- Working hours
- Break periods
- Vacation mode
- Buffer time
- Minimum booking notice
- Maximum advance booking window
- Blocked dates
- Public holidays (future)

Only available time slots may be booked.

# **6.12 Meeting Policy**

All online lessons are conducted through platform-generated meeting links.

Rules:

- Meetings are created automatically.
- Meeting links are visible only to confirmed participants.
- Administrators may join lessons as observers for quality assurance.
- Meeting recordings, when enabled, are owned by the platform.
- Recordings are initially stored in the platform-managed Google Drive and may later be migrated to private cloud storage.

Participants should communicate through platform-approved channels rather than exchanging personal contact details.

# **6.13 Homework Policy**

Homework supports continuous learning.

Rules:

- Instructors may assign homework after lessons.
- Homework may include notes and PDF documents.
- Due dates are supported.
- Students receive reminders before deadlines.
- Homework history remains available for future reference.

# **6.14 Review Policy**

Students may review instructors after completed lessons.

Rules:

- Reviews are linked to completed lessons.
- Students may rate both the instructor and the lesson experience.
- Reviews cannot be submitted before lesson completion.
- Platform moderation may hide reviews that violate community guidelines.

# **6.15 Wallet Policy**

The student wallet is the primary financial account within the platform.

Rules:

- Wallets are maintained in the student's assigned billing currency.
- Wallet balances cannot become negative.
- Wallets support:
  - Recharge
  - Refunds
  - Referral rewards
- Every wallet transaction is permanent and auditable.
- Wallet refunds are preferred over payment reversals for Version 1.

# **6.16 Payment Policy**

Payments are processed only through supported platform payment gateways.

Rules:

- Student-facing pricing is controlled by the platform.
- Instructor compensation is managed internally.
- Students never see instructor compensation.
- Failed payments do not create confirmed bookings.
- Payment confirmations are recorded permanently.

# **6.17 Instructor Compensation & Settlement Policy**

Instructor compensation is determined by the platform rather than public lesson pricing.

Rules:

- Compensation may vary based on instructor level, expertise, and internal business strategy.
- Settlement periods are configurable.
- Instructors request withdrawals through the platform.
- Withdrawal methods depend on the instructor's country.
- Every settlement is recorded in the financial ledger.

# **6.18 Referral Policy**

Student referrals encourage organic platform growth.

Rules:

- Referral campaigns are configurable.
- Rewards are credited to student wallets.
- Campaigns define:
  - Reward type
  - Reward value
  - Eligible lessons
  - Maximum rewarded lessons
  - Campaign duration
- Referral history is permanently recorded.

# **6.19 Waitlist Policy**

Students may join waitlists when instructors have no suitable availability.

Rules:

- Students define preferred availability.
- Waitlists are instructor-specific.
- When a matching slot becomes available, the platform notifies eligible students.
- Booking remains first-come, first-served unless future prioritization rules are introduced.

# **6.20 Learning Plan Policy**

Learning plans encourage structured education.

Rules:

- Students define learning goals.
- Learning plans may span multiple recurring lessons.
- Progress is tracked over time.
- Future AI services may use learning plans to generate personalized recommendations.

# **6.21 Security & Privacy Policies**

The platform shall:

- Protect personal information.
- Encrypt sensitive credentials.
- Maintain audit logs.
- Restrict access through role-based permissions.
- Record administrative actions affecting financial or educational data.

# **6.22 Administrative Override Policy**

Administrators may override automated decisions in exceptional situations.

Examples include:

- Instructor approval
- Refund exceptions
- Booking corrections
- Wallet adjustments
- Account restoration

Every override must:

- Record the administrator responsible.
- Capture the reason.
- Preserve the previous state in audit logs.

# **6.23 Platform Governance Summary**

STEM Learning is governed by policies that prioritize automation, transparency, educational quality, and financial integrity. These rules form the operational contract between the platform, students, instructors, and administrators. Future modules, integrations, and AI capabilities must respect these governance principles to ensure consistent platform behavior as the product evolves.

# **CHAPTER 7 - GLOBALIZATION, LOCALIZATION & REGIONAL BUSINESS STRATEGY**

# **7.1 Introduction**

STEM Learning is designed as a global online learning platform serving students and instructors across multiple countries.

To provide a consistent yet localized experience, the platform separates **global platform behavior** from **regional business configuration**.

Rather than creating separate systems for each country, STEM Learning operates as a single platform where localization is driven through configurable regional settings.

This approach allows the platform to expand internationally without changing the underlying product architecture.

# **7.2 Objectives**

The localization strategy has the following objectives:

- Deliver localized experiences.
- Support multiple countries.
- Support multiple currencies.
- Support multiple time zones.
- Display localized pricing.
- Route payments through appropriate payment gateways.
- Enable regional marketing campaigns.
- Configure business rules by country where required.
- Support future global expansion.

# **7.3 Global Platform Architecture**

The platform operates using two configuration layers.

## **Global Configuration**

Applies to every country.

Examples:

- Authentication
- Booking Engine
- Wallet Engine
- Homework
- Reviews
- Learning Plans
- Meeting Integration
- Instructor Verification Workflow

## **Regional Configuration**

Can vary by country.

Examples:

- Currency
- Payment Gateway
- Language
- Time Zone
- Country Pricing
- Feature Availability
- Referral Campaigns
- Support Contact Information

This separation minimizes duplication while maximizing flexibility.

# **7.4 Country Management**

Every supported country is managed centrally through the administration panel.

Each country represents a business region rather than just a geographic location.

Every country record should contain:

### **Identity**

- Country Name
- ISO Country Code
- Country Flag
- Status (Active / Inactive)

### **Localization**

- Default Currency
- Default Language
- Default Time Zone
- Date Format
- Time Format
- Number Format
- First Day of Week

### **Contact Information**

- Support Email
- Support Phone
- Business Address (Optional)

### **Business Configuration**

- Payment Gateway
- Referral Campaign
- Settlement Rules
- Regional Feature Flags

Countries can be enabled or disabled without affecting historical data.

# **7.5 Currency Strategy**

The platform supports multiple currencies while maintaining a single commercial model.

## **Core Principles**

- Every student has one assigned billing currency.
- Billing currency is determined by the student's selected country.
- Student-facing prices are maintained by the platform.
- Instructor compensation is managed independently.
- Currency changes are controlled to prevent pricing abuse.

## **Currency Master**

Administrators manage all supported currencies.

Each currency includes:

- Currency Name
- ISO Currency Code
- Symbol
- Decimal Precision
- Decimal Separator
- Thousand Separator
- Active Status

Example:

| **Currency** | **Symbol** |
| ------------ | ---------- |
| INR          | ₹          |
| ---          | ---        |
| USD          | \$         |
| ---          | ---        |
| GBP          | £          |
| ---          | ---        |
| EUR          | €          |
| ---          | ---        |
| CAD          | C\$        |
| ---          | ---        |
| AUD          | A\$        |
| ---          | ---        |

# **7.6 Country-to-Currency Mapping**

Each country has one default billing currency.

Examples:

| **Country**    | **Currency** |
| -------------- | ------------ |
| India          | INR          |
| ---            | ---          |
| United States  | USD          |
| ---            | ---          |
| United Kingdom | GBP          |
| ---            | ---          |
| Canada         | CAD          |
| ---            | ---          |
| Australia      | AUD          |
| ---            | ---          |

The platform automatically assigns the correct billing currency during registration.

# **7.7 Student Billing Currency**

The billing currency is established when the student selects their country during registration.

The billing currency determines:

- Lesson pricing
- Wallet balance
- Payments
- Refunds
- Referral rewards
- Invoices

Students may update their billing country only through a controlled process to prevent misuse.

# **7.8 Regional Pricing Strategy**

One of the core business principles of STEM Learning is that **pricing is regional rather than exchange-rate based**.

Prices are determined independently for each country.

Example:

| **Subject** | **Education Level** | **Country**   | **Student Price** |
| ----------- | ------------------- | ------------- | ----------------- |
| Mathematics | High School         | India         | ₹800              |
| ---         | ---                 | ---           | ---               |
| Mathematics | High School         | United States | \$30              |
| ---         | ---                 | ---           | ---               |
| Mathematics | High School         | Canada        | CAD 40            |
| ---         | ---                 | ---           | ---               |

This approach reflects regional purchasing power and business strategy rather than fluctuating currency exchange rates.

# **7.9 Subject Pricing Model**

Pricing is determined using multiple dimensions.

The platform resolves pricing based on:

- Subject
- Education Level
- Lesson Duration
- Student Country
- Billing Currency

Instructors do not publish public prices.

The platform calculates the applicable student price using the configured pricing matrix.

# **7.10 Lesson Duration Strategy**

Lesson durations are configured globally by administrators.

Examples include:

- 30 Minutes
- 45 Minutes
- 60 Minutes
- 90 Minutes
- 120 Minutes

Each supported duration may have different pricing for each subject and country.

This provides pricing flexibility without creating inconsistent lesson options.

# **7.11 Instructor Compensation Strategy**

Instructor compensation is independent of student pricing.

The platform may determine instructor compensation using factors such as:

- Instructor category
- Experience
- Performance
- Qualifications
- Internal compensation policies

Student-facing prices remain confidential and separate from instructor compensation.

# **7.12 Time Zone Strategy**

All lesson scheduling follows a unified time management policy.

### **Internal Storage**

All booking times are stored in Coordinated Universal Time (UTC).

### **User Display**

Students see lesson times in their local time zone.

Instructors see lesson times in their own local time zone.

Administrators may view both local and UTC times when required.

This prevents scheduling conflicts caused by daylight saving changes or international bookings.

# **7.13 Language Strategy**

The platform is designed to support multiple interface languages.

Version 1 may launch with a primary interface language while preparing the architecture for future translations.

Language configuration includes:

- Interface language
- Email templates
- Notification templates
- Static content
- Future AI localization

Instructor teaching languages are managed separately from platform interface languages.

# **7.14 Payment Gateway Routing**

The platform automatically selects the appropriate payment gateway based on the student's billing country.

Examples:

| **Country**    | **Preferred Gateway** |
| -------------- | --------------------- |
| India          | Razorpay              |
| ---            | ---                   |
| United States  | Stripe                |
| ---            | ---                   |
| Canada         | Stripe                |
| ---            | ---                   |
| United Kingdom | Stripe                |
| ---            | ---                   |
| Australia      | Stripe                |
| ---            | ---                   |

Gateway routing is configurable through the administration panel.

# **7.15 Wallet Localization**

Student wallets operate exclusively in the student's assigned billing currency.

The wallet supports:

- Recharge
- Lesson payments
- Refunds
- Referral rewards

Cross-currency wallets are not supported in Version 1.

# **7.16 Refund Strategy**

Refunds follow the platform wallet policy.

Rules:

- Eligible refunds are credited to the student's wallet.
- Refunds remain in the original billing currency.
- Historical financial records remain immutable.

# **7.17 Regional Feature Flags**

Administrators may enable or disable selected features by country.

Examples include:

- Demo Lessons
- Waitlist
- Referral Program
- Homework
- WhatsApp Notifications
- Payment Methods
- Meeting Recording

This allows gradual feature rollouts without affecting all markets.

# **7.18 Regional Support**

Each country may define localized support information, including:

- Support Email
- Support Phone
- Business Hours
- Local Help Content

This prepares the platform for future regional operations.

# **7.19 SEO Localization**

Public instructor profiles and educational content should support regional search optimization.

Future localization may include:

- Country-specific landing pages.
- Regional subject pages.
- Localized metadata.
- Localized educational content.

The objective is to improve discoverability while maintaining a unified platform.

# **7.20 Future International Expansion**

The localization architecture is designed to support future capabilities without redesigning the platform.

Potential enhancements include:

- Additional interface languages.
- Regional marketing campaigns.
- Country-specific promotions.
- Local payment methods.
- Regional compliance requirements.
- Country-specific educational partnerships.

# **7.21 Guiding Principles**

The globalization strategy follows these principles:

- One global platform.
- Regionally configurable business behavior.
- Country-specific pricing.
- Localized user experiences.
- Centralized platform management.
- Consistent educational quality.
- Scalable international expansion.

# **7.22 Chapter Summary**

STEM Learning is designed as a globally scalable platform with region-aware behavior rather than isolated country deployments. Localization is driven through configuration, allowing pricing, currencies, payment routing, languages, support information, and selected business features to adapt to regional requirements while preserving a single, maintainable platform architecture.

# **CHAPTER 8 - LEARNING FRAMEWORK, ACADEMIC STRUCTURE & EDUCATIONAL JOURNEY**

# **8.1 Introduction**

The primary objective of STEM Learning is not simply to facilitate lesson bookings, but to help students achieve measurable educational outcomes through structured and personalized learning.

This chapter defines the academic framework that governs how educational content is organized, how students progress through learning, how instructors deliver education, and how the platform measures learning outcomes.

Every educational feature within the platform-including instructor discovery, booking, homework, progress tracking, analytics, and future AI capabilities-shall follow the framework defined in this chapter.

# **8.2 Learning Philosophy**

The platform follows five core educational principles.

### **Goal-Oriented Learning**

Every student should begin with a clear learning objective rather than immediately booking lessons.

Examples include:

- Improve school performance
- Prepare for board examinations
- Prepare for university entrance examinations
- Learn programming
- Improve spoken English
- Develop professional skills
- Personal interest or lifelong learning

The platform should encourage students to define their objective during onboarding or before booking lessons.

### **Personalized Learning**

Each student's learning journey is unique.

Recommendations, scheduling, homework, progress tracking, and future AI assistance should adapt to the student's goals, preferred pace, and educational background.

### **Continuous Learning**

Learning should be treated as an ongoing journey.

The platform should encourage:

- Recurring lessons
- Homework completion
- Progress reviews
- Long-term instructor relationships

### **Measurable Outcomes**

The platform should make learning progress visible.

Examples include:

- Lessons completed
- Homework completion
- Learning milestones
- Instructor feedback
- Future AI progress summaries

### **Instructor-Guided Learning**

Instructors remain responsible for educational delivery.

The platform provides tools, automation, and reporting but does not replace the instructor's professional judgment.

# **8.3 Academic Structure**

Educational content is organized into a structured hierarchy.

The hierarchy should support expansion without requiring redesign.

Recommended structure:

Subject Category

↓

Subject

↓

Education Level

↓

Topic

↓

Subtopic

↓

Lesson

↓

Homework

Example:

Science

↓

Physics

↓

High School

↓

Mechanics

↓

Newton's Laws

↓

Lesson

↓

Homework

This structure allows future expansion while maintaining consistent organization.

# **8.4 Subject Hierarchy**

Subjects should be organized hierarchically rather than as a flat list.

Illustrative structure:

Mathematics

├── Algebra

├── Geometry

├── Trigonometry

├── Calculus

└── Statistics

Science

├── Physics

├── Chemistry

└── Biology

Computer Science

├── Programming

├── Data Structures

├── Algorithms

└── Databases

The hierarchy is managed through the administration panel and may evolve as new disciplines are introduced.

# **8.5 Education Levels**

Education levels classify the academic stage of the learner.

The administration panel manages the list of supported levels.

Examples:

- Primary School
- Middle School
- High School
- Higher Secondary
- College
- University
- Professional Development

Education levels influence:

- Instructor expertise
- Student search filters
- Regional pricing
- Learning roadmaps
- Homework complexity
- Analytics

# **8.6 Learning Goals**

Learning goals define what a student intends to achieve.

Examples:

### **Academic**

- Improve grades
- Complete school syllabus
- Prepare for examinations

### **Professional**

- Learn programming
- Improve technical skills
- Prepare for interviews

### **Personal Development**

- Learn a new language
- Develop a hobby
- Explore a new subject

Learning goals are used to:

- Recommend instructors
- Recommend lesson frequency
- Measure progress
- Generate future AI insights

Students may maintain multiple learning goals over time.

# **8.7 Learning Plans**

A learning plan provides structure for achieving a learning goal.

A learning plan typically includes:

- Learning goal
- Subject
- Education level
- Preferred instructor
- Lesson frequency
- Lesson duration
- Target completion period
- Progress milestones

A student may have multiple learning plans but should normally have one active plan per subject.

Learning plans may evolve as educational needs change.

# **8.8 Subject Roadmaps**

A roadmap organizes learning into logical stages.

Example:

Programming

↓

Programming Fundamentals

↓

Variables & Data Types

↓

Control Structures

↓

Functions

↓

Object-Oriented Programming

↓

Projects

Roadmaps help:

- Instructors plan lessons.
- Students visualize progress.
- Future AI recommend next topics.

Version 1 supports administrator-managed roadmaps with future instructor customization under controlled policies.

# **8.9 Lesson Structure**

Every lesson should be treated as a structured learning event.

A lesson typically includes:

- Subject
- Topic
- Instructor
- Student
- Scheduled time
- Duration
- Attendance
- Completion status
- Homework (optional)
- Instructor notes (future)
- Learning outcome

Lessons remain permanently associated with the student's learning history.

# **8.10 Homework Framework**

Homework reinforces learning outside scheduled lessons.

Version 1 supports:

- Instructor notes
- PDF documents
- Due dates
- Completion status

Future enhancements may include:

- Interactive assignments
- AI-generated exercises
- Automated assessment
- Code execution environments
- Practice quizzes

Homework contributes to the student's learning timeline.

# **8.11 Learning Progress**

Student progress is measured using educational activity rather than only attendance.

Indicators include:

- Lessons completed
- Homework completion
- Learning milestones achieved
- Recurring lesson consistency
- Instructor evaluations
- Future AI assessments

Progress should be visible through intuitive dashboards and reports.

# **8.12 Learning Timeline**

Every student maintains a chronological educational history.

Examples include:

- Learning goal created
- Demo lesson completed
- First paid lesson
- Homework assigned
- Homework completed
- Milestone achieved
- Instructor changed
- Learning plan completed

The timeline provides continuity and supports future analytics.

# **8.13 Instructor Educational Responsibilities**

Instructors are responsible for:

- Delivering quality lessons
- Following the student's learning plan
- Assigning homework when appropriate
- Monitoring educational progress
- Encouraging consistent learning
- Maintaining professional teaching standards

The platform supports these responsibilities but does not replace educational decision-making.

# **8.14 Student Educational Responsibilities**

Students are encouraged to:

- Maintain active learning goals
- Attend scheduled lessons
- Complete homework
- Participate in recurring learning
- Provide feedback
- Track progress
- Update educational objectives when necessary

# **8.15 Learning Analytics**

Version 1 provides foundational educational analytics.

Students should be able to view:

- Lessons completed
- Upcoming lessons
- Homework status
- Active learning plans
- Favorite instructors

Instructors should be able to view:

- Teaching hours
- Active students
- Homework assigned
- Lesson completion
- Student retention
- Demo-to-paid conversion

Administrators should be able to monitor:

- Subject popularity
- Learning goal trends
- Student retention
- Instructor performance
- Lesson completion rates
- Country-wise educational activity

# **8.16 Future AI Learning Strategy**

The platform is designed to support AI-powered educational services without changing the academic model.

Potential future capabilities include:

### **Lesson Summary**

Generate structured summaries after each lesson.

### **Homework Review**

Provide AI-assisted feedback before instructor review.

### **Personalized Recommendations**

Suggest instructors, schedules, and learning resources based on the student's goals and history.

### **Progress Insights**

Identify strengths, weaknesses, and areas requiring additional attention.

### **Study Planning**

Recommend lesson frequency, homework priorities, and milestone adjustments.

### **Learning Assistant**

Provide an AI assistant that understands the student's learning history, homework, completed lessons, and educational objectives.

# **8.17 Educational Quality Principles**

The platform is committed to maintaining educational quality through the following principles:

- Qualified instructors
- Structured learning
- Consistent scheduling
- Meaningful homework
- Progress visibility
- Student feedback
- Continuous improvement

Educational quality should remain a primary success metric for the platform.

# **8.18 Chapter Summary**

The educational framework defined in this chapter establishes STEM Learning as a structured learning platform rather than a simple scheduling marketplace. By organizing learning around goals, plans, roadmaps, lessons, homework, and measurable progress, the platform creates a foundation for long-term student success while remaining flexible enough to support future AI-powered educational experiences.

# **CHAPTER 9 - SECURITY, PRIVACY, TRUST & COMPLIANCE**

# **9.1 Introduction**

Trust is fundamental to the success of an online learning marketplace.

Students trust the platform with their personal information, educational progress, payment details, and learning history. Instructors trust the platform with their professional identity, earnings, documents, and reputation. Administrators are responsible for maintaining the integrity of the marketplace while ensuring secure, reliable, and transparent operations.

The purpose of this chapter is to establish the security, privacy, and governance principles that apply throughout the STEM Learning platform.

These principles guide every business process, functional module, integration, and future enhancement.

# **9.2 Security Objectives**

The platform shall achieve the following security objectives:

- Protect user identities.
- Protect financial transactions.
- Protect educational records.
- Protect instructor verification documents.
- Prevent unauthorized access.
- Maintain complete auditability.
- Support secure global operations.
- Minimize fraud and abuse.
- Preserve data integrity.
- Build long-term user trust.

# **9.3 Trust Framework**

STEM Learning establishes trust through multiple layers.

## **Identity Trust**

Only verified users may access protected functionality.

Instructor accounts require identity verification before activation.

## **Educational Trust**

Students should be confident that instructors have completed the platform's verification process.

Instructor profiles should clearly display verification status where appropriate.

## **Financial Trust**

All payments, wallet transactions, settlements, and refunds shall be traceable.

Financial records must remain immutable.

## **Operational Trust**

Administrative actions affecting users or finances shall be recorded for accountability.

# **9.4 Identity & Authentication Policy**

The platform supports secure account authentication for all users.

### **Student Authentication**

Students authenticate using:

- Email
- Password

Future enhancements may include:

- Social login
- Passwordless authentication
- Multi-factor authentication

### **Instructor Authentication**

Instructor authentication follows the same process as students but requires additional verification before platform activation.

### **Administrator Authentication**

Administrative accounts require stronger authentication controls.

Examples include:

- Multi-factor authentication
- Device verification
- Session monitoring

Administrative access should be limited to authorized personnel.

# **9.5 Authorization Policy**

Access to platform functionality is governed by role-based permissions.

Primary roles include:

- Guest
- Student
- Instructor
- Administrator

Each role may access only the functionality required for its responsibilities.

Administrative permissions should support fine-grained control to accommodate future operational roles.

# **9.6 Instructor Verification & KYC**

Instructor trust begins with identity verification.

Every instructor application follows a configurable verification process.

Potential verification documents include:

- Government-issued identification
- Profile photograph
- Address verification
- Educational qualifications
- Professional certifications
- Resume
- Introduction video

Administrators may define:

- Required documents
- Optional documents
- Accepted file types
- Verification workflow
- Renewal requirements

Verification records remain associated with the instructor profile for audit purposes.

# **9.7 Privacy Principles**

The platform follows these privacy principles:

- Collect only necessary information.
- Use information only for legitimate business purposes.
- Protect personal information.
- Minimize unnecessary exposure of user data.
- Allow users to manage their personal information where appropriate.
- Maintain transparency regarding platform data usage.

# **9.8 Personal Data Protection**

Personal information includes, but is not limited to:

- Name
- Email address
- Phone number
- Country
- Profile photographs
- Verification documents
- Educational history
- Payment history

The platform shall ensure that access to personal information is limited to authorized users with a legitimate business need.

# **9.9 Educational Data Protection**

Educational information is confidential.

Examples include:

- Learning goals
- Lesson history
- Homework
- Progress records
- Reviews
- Learning plans

Students retain access to their educational records throughout their use of the platform.

Educational records should not be disclosed to unrelated parties.

# **9.10 Financial Data Protection**

Financial information includes:

- Wallet balances
- Payments
- Refunds
- Withdrawals
- Referral rewards
- Invoices
- Settlement history

The platform shall ensure:

- Transaction integrity.
- Permanent transaction history.
- Accurate reporting.
- Controlled administrative adjustments.

Instructor compensation information is confidential and not visible to students.

# **9.11 Meeting Security**

Online lessons are conducted through platform-generated meeting links.

Security principles include:

- Only confirmed participants may access meeting links.
- Meeting links should not remain valid indefinitely.
- Administrators may join meetings for quality assurance.
- Meeting recordings remain under platform ownership.
- Recording access follows defined permissions.

Participants should communicate through approved platform channels.

# **9.12 Communication Privacy**

Platform communication includes:

- Email
- SMS
- WhatsApp
- In-platform messaging (after confirmed bookings)

Personal contact information should not be unnecessarily exposed through the platform.

The platform should discourage attempts to bypass official communication channels.

# **9.13 Fraud Prevention**

The platform shall implement business controls to reduce fraud.

Examples include:

### **Student Fraud**

- Repeated free demo abuse.
- Payment abuse.
- Referral manipulation.
- Multiple account creation.

### **Instructor Fraud**

- Fake verification documents.
- Repeated lesson cancellations.
- Artificial ratings.
- Circumventing platform payment policies.

### **Administrative Fraud**

Administrative actions shall remain fully auditable.

No administrator should be able to perform critical financial actions without an audit trail.

# **9.14 Platform Abuse Prevention**

The platform should monitor unusual behavior, including:

- Excessive login failures.
- Suspicious booking activity.
- Referral abuse.
- Wallet abuse.
- Unauthorized access attempts.

Future versions may introduce automated risk scoring.

# **9.15 Audit Logging**

Every significant business event should be recorded.

Examples include:

- Registration
- Login
- Profile updates
- Instructor approval
- Booking creation
- Booking cancellation
- Wallet transactions
- Withdrawal requests
- Administrative overrides
- Configuration changes

Audit records should include:

- Event
- User
- Timestamp
- Previous value (where applicable)
- Updated value
- Responsible actor

Audit logs should be retained according to platform policies.

# **9.16 Activity History**

Each major business entity should maintain its own activity timeline.

Examples include:

Student Timeline

- Registration
- First booking
- First payment
- Homework completion
- Learning milestone

Instructor Timeline

- Registration
- KYC submission
- Approval
- First lesson
- Withdrawal

Booking Timeline

- Created
- Confirmed
- Meeting generated
- Completed
- Reviewed

# **9.17 Administrative Accountability**

Administrative actions affecting platform operations should be transparent.

Examples include:

- Instructor approval
- Instructor suspension
- Wallet adjustments
- Booking modifications
- Refund exceptions
- Feature configuration

Every administrative action should be attributable to a specific administrator.

# **9.18 Data Retention**

Different categories of information may follow different retention policies.

Examples include:

- User profiles
- Educational history
- Financial records
- Audit logs
- Meeting recordings
- Verification documents

Retention periods should be configurable where appropriate while respecting applicable legal obligations.

# **9.19 Account Lifecycle**

Accounts progress through defined operational states.

### **Student**

- Registered
- Active
- Suspended
- Archived

### **Instructor**

- Draft
- Submitted
- Under Review
- Approved
- Active
- Vacation
- Suspended
- Archived

Historical educational and financial records remain preserved even if an account is archived.

# **9.20 Business Continuity**

The platform should be designed to minimize service interruptions.

Operational objectives include:

- Reliable booking management.
- Preservation of financial data.
- Preservation of educational history.
- Recovery from operational failures.
- Controlled deployment processes.

Business continuity planning will evolve as the platform grows.

# **9.21 Compliance Principles**

Version 1 is designed with the following high-level compliance principles:

- Respect user privacy.
- Maintain transparent financial records.
- Protect educational information.
- Maintain verifiable instructor identities.
- Preserve auditability.
- Support future regional compliance requirements.

Country-specific legal compliance may be introduced as the platform expands internationally.

# **9.22 Security Governance**

Security is a continuous operational responsibility rather than a one-time implementation activity.

Platform governance includes:

- Periodic review of security policies.
- Monitoring operational risks.
- Reviewing instructor verification processes.
- Improving fraud prevention.
- Updating administrative controls.
- Responding to newly identified threats.

# **9.23 Trust Indicators**

To strengthen confidence, the platform should clearly communicate trust signals to users.

Examples include:

- Verified instructor badges.
- Secure payment messaging.
- Transparent cancellation policies.
- Public instructor reviews.
- Platform support availability.
- Privacy commitments.

These indicators should be visible where they help users make informed decisions.

# **9.24 Chapter Summary**

Security, privacy, trust, and compliance are foundational principles of STEM Learning. Every business process-from instructor onboarding to lesson delivery, payments, and reporting-must operate within these governance standards. By combining strong operational controls with transparent policies, the platform establishes a trusted environment that supports long-term educational relationships and scalable global growth.

Book 2

# **Enterprise Software Requirements Specification (SRS) v2.0**

# **BOOK 2 - FUNCTIONAL REQUIREMENTS**

# **Book Overview**

## **Purpose**

Book 2 defines the detailed functional behavior of every business domain within the STEM Learning platform.

Unlike Book 1, which defines the business vision, governance, and product strategy, this book specifies exactly how the platform shall behave from a functional perspective.

Each requirement in this document is uniquely identified to support:

- Requirement traceability
- UI/UX design
- Software development
- Testing
- Quality assurance
- Future enhancements

All requirements defined in this book inherit the governance, policies, and business rules established in Book 1.

# **Requirement Classification**

Every requirement shall use the following prefixes.

| **Prefix** | **Meaning**                            |
| ---------- | -------------------------------------- |
| FR         | Functional Requirement                 |
| ---        | ---                                    |
| BR         | Business Rule                          |
| ---        | ---                                    |
| VR         | Validation Rule                        |
| ---        | ---                                    |
| WF         | Workflow                               |
| ---        | ---                                    |
| AC         | Acceptance Criteria                    |
| ---        | ---                                    |
| NFR        | Non-Functional Requirement (Reference) |
| ---        | ---                                    |
| FUT        | Future Enhancement                     |
| ---        | ---                                    |

Example

FR-AUTH-001

BR-BOOK-012

VR-STU-007

WF-PAY-004

AC-KYC-003

# **Requirement Priority**

Each requirement shall include a priority.

| **Priority** | **Meaning**                   |
| ------------ | ----------------------------- |
| Critical     | Mandatory for Version 1       |
| ---          | ---                           |
| High         | Required before production    |
| ---          | ---                           |
| Medium       | Important but may be deferred |
| ---          | ---                           |
| Low          | Future enhancement            |
| ---          | ---                           |

# **Requirement Status**

Each requirement maintains lifecycle status.

- Draft
- Approved
- Implemented
- Tested
- Released
- Deprecated

# **Book Structure**

## **PART A - Identity & User Lifecycle**

Chapter 1

Authentication & Authorization

Chapter 2

Student Management

Chapter 3

Instructor Management

# **PART B - Academic Foundation**

Chapter 4

Subjects

Chapter 5

Education Levels

Chapter 6

Learning Plans

Chapter 7

Homework

# **PART C - Marketplace**

Chapter 8

Instructor Marketplace

Chapter 9

Availability Engine

Chapter 10

Booking Engine

Chapter 11

Meeting Engine

# **PART D - Financial**

Chapter 12

Wallet

Chapter 13

Payments

Chapter 14

Instructor Settlement & Withdrawals

Chapter 15

Referral Program

# **PART E - Engagement**

Chapter 16

Notifications

Chapter 17

Reviews & Ratings

# **PART F - Platform**

Chapter 18

Reports & Analytics

Chapter 19

CMS

Chapter 20

Global Settings

Chapter 21

Localization

Chapter 22

Activity Timeline & Audit Logs

# **Functional Chapter Template**

Every chapter follows the same structure.

- Introduction
- Purpose
- Business Objectives
- Scope
- Functional Requirements
- Business Rules
- User Roles
- User Workflows
- Validation Rules
- Exception Handling
- Notifications
- Reports
- Administrative Configuration
- Acceptance Criteria
- Future Enhancements

This structure ensures consistency across every business domain.

PART A

# **PART A - IDENTITY & USER LIFECYCLE**

# **CHAPTER 1 - AUTHENTICATION & AUTHORIZATION**

# **1.1 Introduction**

Authentication and Authorization form the foundation of the STEM Learning platform.

This module is responsible for securely identifying users, protecting access to platform resources, enforcing permissions, and maintaining account integrity.

The authentication system must provide a seamless user experience while ensuring enterprise-grade security suitable for a global online learning marketplace.

This chapter defines the functional behavior for user registration, authentication, session management, password recovery, email verification, authorization, and future identity management capabilities.

# **1.2 Objectives**

The Authentication & Authorization module shall:

- Securely authenticate all users.
- Protect access to platform resources.
- Verify user identity before granting sensitive privileges.
- Support role-based authorization.
- Maintain secure user sessions.
- Prevent unauthorized access.
- Support future authentication methods without redesign.

# **1.3 Scope**

This chapter covers:

### **User Registration**

- Student registration
- Instructor registration
- Administrator account creation

### **Authentication**

- Login
- Logout
- Remember Me
- Session Management

### **Account Recovery**

- Forgot Password
- Reset Password

### **Verification**

- Email Verification
- Future Mobile Verification

### **Authorization**

- Roles
- Permissions
- Access Control

### **Security**

- Login protection
- Session security
- Audit logging

# **1.4 Supported User Types**

The platform supports the following user types.

| **User Type** | **Self Registration** | **Login** | **Dashboard**        |
| ------------- | --------------------- | --------- | -------------------- |
| Guest         | No                    | No        | No                   |
| ---           | ---                   | ---       | ---                  |
| Student       | Yes                   | Yes       | Yes                  |
| ---           | ---                   | ---       | ---                  |
| Instructor    | Yes                   | Yes       | Yes (after approval) |
| ---           | ---                   | ---       | ---                  |
| Administrator | No                    | Yes       | Yes                  |
| ---           | ---                   | ---       | ---                  |

Administrator accounts are created internally.

# **1.5 Authentication Principles**

The authentication system shall follow these principles:

- Security by default.
- Minimal friction for legitimate users.
- Role-based access control.
- Email-first identity.
- Platform-managed authorization.
- Complete auditability.

# **1.6 Functional Requirements**

## **Registration**

**Title:** Student Registration

**Priority:** Critical

**Description**

The system shall allow new students to create an account using:

- First Name
- Last Name
- Email Address
- Password
- Country
- Acceptance of Terms & Privacy Policy

**Result**

A student account shall be created in an unverified state.

**Title:** Instructor Registration

**Priority:** Critical

**Description**

The system shall allow prospective instructors to register separately from students.

Instructor registration creates an instructor application that must complete the approval workflow before teaching privileges are granted.

**Title:** Email Uniqueness

**Priority:** Critical

The system shall prevent duplicate email addresses across all account types.

Each email address shall uniquely identify one user account.

**Title:** Password Requirements

**Priority:** Critical

The system shall enforce configurable password policies, including minimum length and complexity requirements.

**Title:** Terms Acceptance

**Priority:** Critical

Users must explicitly accept the platform's Terms of Service and Privacy Policy before registration can be completed.

The system shall record the accepted policy version and acceptance timestamp.

# **Email Verification**

- The system shall send a verification email immediately after successful registration.
- The system shall require email verification before a student can book any lesson or access protected learning features.
- The system shall allow users to request a new verification email if the previous one expires.
- The system shall prevent expired verification links from being used.

# **Login**

- Users shall authenticate using:
  - Email
  - Password
- The system shall support "Remember Me" functionality for trusted devices.
- The system shall reject invalid authentication attempts without revealing whether the email or password was incorrect.
- Only approved instructors may access instructor teaching functionality. An instructor whose application is pending may sign in to monitor application status but shall not have access to lesson management or instructor-only operational features.
- Suspended or archived accounts shall be prevented from authenticating until their status changes.

# **Logout**

- The system shall allow authenticated users to securely end their current session.
- The system shall invalidate the active session immediately upon logout.

# **Password Recovery**

- The system shall allow users to request a password reset using their registered email address.
- Password reset links shall expire after a configurable period.
- After a successful password reset, the previous password shall no longer be valid.

# **Session Management**

- The system shall maintain authenticated sessions until logout, expiration, or invalidation.
- The system shall automatically expire inactive sessions after a configurable period.
- The system shall record authentication events, including successful logins, failed attempts, password resets, and logouts, in the platform's activity history.

# **CHAPTER 2 - STUDENT MANAGEMENT**

# **2.1 Introduction**

The Student Management module governs the complete lifecycle of a student within the STEM Learning platform.

Unlike traditional user profile modules that primarily store personal information, this module establishes the student's educational identity, learning preferences, progress, engagement history, financial profile, and long-term relationship with the platform.

The objective is to provide every student with a personalized learning experience while maintaining accurate educational records and minimizing administrative intervention.

The Student Management module integrates with nearly every other business domain, including Authentication, Learning Plans, Instructor Marketplace, Booking Engine, Homework, Wallet, Payments, Reviews, Notifications, and Analytics.

# **2.2 Objectives**

The Student Management module shall:

- Maintain a comprehensive student profile.
- Support personalized learning experiences.
- Store educational preferences.
- Support learning goals and learning plans.
- Maintain complete learning history.
- Integrate with booking and financial modules.
- Track engagement and educational progress.
- Support future AI-powered personalization.

# **2.3 Scope**

This module includes:

### **Student Profile**

- Personal information
- Profile photograph
- Contact information
- Country
- Timezone
- Language preference

### **Learning Profile**

- Learning goals
- Preferred subjects
- Education level
- Preferred lesson durations
- Preferred learning schedule

### **Student Dashboard**

- Upcoming lessons
- Homework
- Learning plans
- Wallet
- Notifications
- Favorite instructors
- Progress overview

### **Learning History**

- Lessons
- Homework
- Reviews
- Payments
- Wallet transactions
- Instructor history

### **Preferences**

- Communication
- Timezone
- Language
- Notification preferences

### **Engagement**

- Favorites
- Waitlist
- Referral
- Learning analytics

# **2.4 Student Lifecycle**

Every student progresses through the following lifecycle.

Guest

↓

Registration

↓

Email Verification

↓

Profile Completion

↓

Learning Goal

↓

Instructor Discovery

↓

Demo Lesson

↓

Paid Learning

↓

Recurring Learning

↓

Learning Completion

↓

Lifelong Learning

Students remain active participants throughout their educational journey.

# **2.5 Student Profile**

The platform maintains a comprehensive student profile.

The profile represents the student's identity across all platform modules.

The profile should include:

## **Personal Information**

- First Name
- Last Name
- Display Name
- Date of Birth (Optional)
- Gender (Optional)
- Profile Photograph

## **Contact Information**

- Email Address
- Phone Number (Optional for Version 1)

## **Regional Information**

- Country
- Timezone
- Preferred Language
- Billing Currency (Derived from Country)

## **Educational Information**

- Current Education Level
- Preferred Subjects
- Learning Goals
- Preferred Learning Style (Future)

# **2.6 Functional Requirements**

## **Student Profile**

### **FR-STU-001**

**Title:** Student Profile Creation

**Priority:** Critical

The system shall automatically create a student profile immediately after successful registration.

### **FR-STU-002**

Every student shall have exactly one active student profile.

### **FR-STU-003**

The student profile shall remain associated with the account throughout its lifecycle.

Historical learning and financial records shall remain linked even if the account is archived.

### **FR-STU-004**

Students shall be able to update their personal profile information.

Editable information includes:

- Name
- Profile Photo
- Timezone
- Preferred Language

Country changes shall follow the regional billing policy defined in Book 1.

### **FR-STU-005**

Students shall be able to upload a profile photograph.

Supported image formats and maximum file size shall be configurable.

### **FR-STU-006**

Students shall not be permitted to create multiple profiles.

## **Learning Goals**

### **FR-STU-007**

Students shall be encouraged to define at least one learning goal before booking their first paid lesson.

Learning goals may include:

- School Preparation
- Board Examination
- University
- Competitive Examination
- Programming
- Professional Development
- Personal Learning

### **FR-STU-008**

Students may create multiple learning goals over time.

Only one active learning goal per subject is recommended.

### **FR-STU-009**

Students shall be able to modify or archive existing learning goals.

Historical learning records shall remain unchanged.

## **Subject Preferences**

### **FR-STU-010**

Students shall be able to identify preferred subjects.

These preferences improve instructor recommendations and future AI-assisted matching.

### **FR-STU-011**

Preferred subjects shall not restrict access to other subjects.

## **Favorite Instructors**

### **FR-STU-012**

Students shall be able to save instructors to a personal favorites list before or after booking.

### **FR-STU-013**

Favorite instructors shall be accessible from the student dashboard.

### **FR-STU-014**

Students shall be notified when a favorited instructor becomes available after joining a waitlist, where applicable.

## **Dashboard**

### **FR-STU-015**

The system shall provide every student with a personalized dashboard after authentication.

### **FR-STU-016**

The dashboard shall display:

- Upcoming Lessons
- Homework Due
- Active Learning Plans
- Wallet Balance
- Recent Notifications
- Favorite Instructors
- Referral Summary
- Learning Progress

### **FR-STU-017**

Dashboard information shall update automatically based on current platform activity.

## **Learning Timeline**

- The platform shall maintain a chronological learning timeline for every student.
- Timeline events include:
  - Registration
  - First Demo
  - First Paid Lesson
  - Homework Assigned
  - Homework Submitted
  - Learning Goal Created
  - Learning Plan Completed
  - Reviews Submitted
- The timeline shall be available only to the student and authorized administrators.

## **Preferences**

- Students shall be able to manage communication preferences.
- Supported notification channels include:
  - Email
  - SMS
  - WhatsApp
- Channel availability depends on platform configuration.
- Students shall be able to configure reminder preferences where permitted by platform settings.

## **Waitlist**

- Students may join instructor waitlists when preferred time slots are unavailable.
- Students shall receive notifications when matching availability becomes available.

## **Referral**

- Students shall receive a unique referral code.
- Eligible referral rewards shall be credited to the student's wallet according to the active referral campaign.

## **Wallet Integration**

- Students shall be able to access wallet information directly from the dashboard.
- Wallet balances shall always be displayed in the student's assigned billing currency.

## **Privacy**

- Students shall control the visibility of optional profile information where permitted by platform policy.
- Student personal information shall not be visible to unrelated users.

## **Account Lifecycle**

Student accounts may transition through the following operational states:

- Registered
- Active
- Suspended
- Archived

Archived accounts shall preserve:

- Learning history
- Financial records
- Homework
- Reviews
- Booking history

# **2.7 Business Rules**

- A student account shall own exactly one student profile.
- Students may book lessons with multiple instructors simultaneously.
- Students may maintain multiple learning goals but should normally have one active learning plan per subject.
- Country changes affecting billing currency shall follow the platform's localization policy and may require administrative review.
- Historical educational and financial records shall never be reassigned to another account.

# **2.8 Validation Rules**

Examples include:

- Email addresses shall remain unique.
- Required profile fields must be completed before the first paid booking.
- Profile photographs must comply with configured format and size restrictions.
- Learning goals should be associated with a valid subject.

# **2.9 Acceptance Criteria**

Examples:

- A newly registered student automatically receives a student profile.
- Students can update editable profile information without affecting historical records.
- Students can create, modify, and archive learning goals.
- Students can manage favorite instructors and receive availability notifications where applicable.

# **2.10 Future Enhancements**

Potential future capabilities include:

- Parent-managed learner accounts.
- AI-generated learning profiles.
- Learning style assessments.
- Personalized educational recommendations.
- Achievement badges and gamification.
- Study groups.
- Academic portfolio generation.
- Cross-device synchronization.

# **Chapter 3 - Instructor Management & Professional Lifecycle**

Because we are not only managing instructors.

We are managing

- Recruitment
- Verification
- Approval
- Professional Profile
- Availability
- Teaching
- Earnings
- Growth
- Analytics
- Suspension
- Retirement

That's an entire lifecycle.

# **Estimated Size**

This chapter alone will likely become

**180-220 Functional Requirements**

approximately **80-100 pages**

because it governs almost every business process.

# **Proposed Structure**

## **3.1 Introduction**

## **3.2 Purpose**

## **3.3 Scope**

## **3.4 Instructor Lifecycle**

Visitor

↓

Become Instructor

↓

Account Registration

↓

Email Verification

↓

Profile Completion

↓

Professional Details

↓

KYC Upload

↓

Application Submitted

↓

Admin Review

↓

Additional Documents (if needed)

↓

Approved

↓

Onboarding

↓

Configure Availability

↓

Publish Profile

↓

Receive Demo Bookings

↓

Receive Paid Bookings

↓

Recurring Students

↓

Performance Growth

↓

Withdraw Earnings

↓

Vacation

↓

Suspended

↓

Archived

## **3.5 Instructor Categories**

Version 1

- Freelancer

Future

- Full Time
- Part Time
- Corporate
- Institution
- Mentor

## **3.6 Instructor Status**

Example

Draft

↓

Registered

↓

Email Verified

↓

Application Started

↓

Application Submitted

↓

Under Review

↓

Interview Required

↓

Document Verification

↓

Approved

↓

Rejected

↓

Active

↓

Vacation

↓

Suspended

↓

Archived

## **3.7 Instructor Profile**

This becomes one of the biggest sections.

Instead of a simple profile we'll define

### **Personal**

- Name
- Photo
- DOB
- Gender

### **Professional**

- Headline

Example

Senior Mathematics Instructor

### **Biography**

### **Teaching Experience**

### **Education**

### **Certifications**

### **Languages**

### **Subjects**

### **Education Levels**

### **Introduction Video**

### **Teaching Philosophy**

### **Response Time**

### **Demo Availability**

### **Public SEO**

### **Verification Badge**

## **3.8 Instructor KYC**

One of the biggest enterprise sections.

Supported documents

Government ID

Passport

Driving License

Degree

Teaching Certificate

Resume

Police Verification (future)

Address Proof

Selfie Verification (future)

Video Verification (future)

Everything configurable.

## **3.9 Instructor Application Workflow**

Very detailed workflow.

## **3.10 Instructor Dashboard**

Widgets

Today's Lessons

Upcoming Lessons

Homework

Pending Reviews

Earnings

Settlement

Analytics

Notifications

## **3.11 Instructor Analytics**

Students

Lessons

Retention

Demo Conversion

Rating

Attendance

Completion

Homework

Revenue

## **3.12 Earnings**

Not payouts yet.

Only earnings.

## **3.13 Teaching Resources**

PDF

Notes

Reusable Resources

## **3.14 Vacation Mode**

One of our earlier decisions.

## **3.15 Functional Requirements**

Example

### **FR-INS-001**

**Requirement Name**

Instructor Profile Creation

**Priority**

Critical

**Actors**

Prospective Instructor

Administrator

**Description**

The system shall automatically create an Instructor Application immediately after an authenticated user chooses **Become an Instructor** and accepts the Instructor Terms.

The application shall remain in **Draft** status until the required information is completed.

**Preconditions**

- Authenticated account.
- Email verified.

**Postconditions**

- Instructor Application exists.
- Dashboard displays onboarding progress.

**Dependencies**

Authentication

Country

Subjects

Languages

**Acceptance Criteria**

Application successfully created.

This format is much closer to what enterprise product teams use because every requirement is self-contained.

PART B

# **PART B - ACADEMIC FOUNDATION**

# **CHAPTER 4 - ACADEMIC FRAMEWORK & CURRICULUM MANAGEMENT**

# **4.1 Introduction**

The Academic Framework & Curriculum Management module defines the educational structure of the STEM Learning platform.

This module establishes how academic disciplines, subjects, education levels, topics, learning outcomes, and curricula are organized and managed.

Rather than functioning as a simple list of subjects, this module provides a structured academic taxonomy that supports personalized learning, instructor specialization, marketplace search, curriculum planning, homework organization, reporting, analytics, and future AI-assisted learning.

Every educational feature within the platform shall reference the academic framework defined in this chapter.

# **4.2 Objectives**

The Academic Framework shall:

- Organize educational content consistently.
- Support multiple education systems.
- Support multiple countries.
- Define structured curricula.
- Enable intelligent instructor matching.
- Support learning plans.
- Enable structured homework.
- Improve marketplace search.
- Support analytics.
- Prepare the platform for AI-powered educational services.

# **4.3 Scope**

This module includes:

### **Academic Structure**

- Subject Categories
- Subjects
- Education Levels
- Skill Levels
- Topics
- Subtopics
- Learning Outcomes

### **Curriculum**

- Learning Roadmaps
- Topic Sequence
- Prerequisites
- Milestones

### **Academic Configuration**

- Languages
- Subject Visibility
- Active Status
- Regional Availability

### **Administration**

- Academic Masters
- Curriculum Management
- Subject Configuration
- Education Level Configuration

# **4.4 Academic Taxonomy**

The platform organizes educational content through a hierarchical taxonomy.

Academic Category

│

▼

Subject

│

▼

Education Level

│

▼

Skill Level (Optional)

│

▼

Curriculum

│

▼

Module

│

▼

Topic

│

▼

Subtopic

│

▼

Learning Outcome

│

▼

Lesson

│

▼

Homework

This hierarchy is conceptual and defines how educational content relates to one another. It does not prescribe a specific database implementation.

# **4.5 Academic Categories**

Academic Categories are the highest level of educational organization.

Examples include:

- Mathematics
- Science
- Computer Science
- Languages
- Business
- Arts
- Test Preparation
- Professional Skills

Categories support:

- Marketplace navigation
- Curriculum organization
- Analytics
- SEO
- Reporting

Categories may contain one or more subjects.

# **4.6 Subjects**

A Subject represents an academic discipline or teaching area.

Examples:

- Algebra
- Physics
- Chemistry
- English
- Python Programming
- Java
- Data Structures
- Accounting

Every subject belongs to one Academic Category.

A subject may be:

- Active
- Inactive
- Archived

Historical lessons remain linked even if a subject is archived.

# **4.7 Education Levels**

Education Levels define the academic stage at which a subject is taught.

Examples include:

- Primary School
- Middle School
- High School
- Higher Secondary
- Undergraduate
- Postgraduate
- Professional Development
- Lifelong Learning

Education Levels influence:

- Instructor eligibility.
- Student search filters.
- Lesson pricing.
- Curriculum.
- Homework complexity.
- Learning plans.

# **4.8 Skill Levels**

Some subjects require skill-based progression rather than formal education levels.

Examples:

Programming

- Beginner
- Intermediate
- Advanced

English

- Basic
- Intermediate
- Fluent

Music

- Beginner
- Grade 1-8
- Professional

Skill Levels are optional and configurable.

# **4.9 Curriculum**

A Curriculum defines the recommended sequence of learning within a subject.

Each curriculum contains:

- Subject
- Education Level
- Version
- Active Status
- Modules
- Learning Outcomes

Multiple curriculum versions may coexist to support changes over time while preserving historical learning records.

# **4.10 Curriculum Modules**

A curriculum is divided into Modules.

Example:

Python Programming

Module 1

Introduction

Module 2

Variables

Module 3

Control Structures

Module 4

Functions

Module 5

Object-Oriented Programming

Modules provide logical grouping of related topics.

# **4.11 Topics**

Topics represent major learning units within a module.

Example:

Module

Functions

Topics

- Function Definition
- Parameters
- Return Values
- Scope

Topics support:

- Lesson planning.
- Homework assignment.
- Learning analytics.
- Future AI recommendations.

# **4.12 Subtopics**

Subtopics provide additional granularity.

Example:

Topic

Functions

Subtopics

- Default Parameters
- Named Parameters
- Anonymous Functions
- Recursive Functions

Subtopics improve educational organization without requiring additional subjects.

# **4.13 Learning Outcomes**

Every curriculum should define measurable learning outcomes.

Examples:

Students should be able to:

- Solve quadratic equations.
- Build simple Python applications.
- Communicate effectively in English.
- Apply Newton's Laws to practical problems.

Learning outcomes are used to:

- Evaluate progress.
- Structure learning plans.
- Guide instructors.
- Support future AI assessments.

# **4.14 Subject Languages**

Each subject may support one or more teaching languages.

Example:

Mathematics

- English
- Hindi

Programming

- English

Spanish

- Spanish

Teaching language availability influences instructor search and recommendations.

# **4.15 Regional Availability**

Subjects may be enabled or disabled for specific countries.

Example:

A subject offered in one region may not yet be available in another due to instructor availability or business strategy.

Regional availability affects:

- Marketplace visibility.
- Search results.
- Pricing.
- Marketing.

# **4.16 Marketplace Integration**

The Marketplace uses the Academic Framework to:

- Search instructors.
- Filter instructors.
- Display subjects.
- Display education levels.
- Recommend instructors.

No marketplace component should define academic information independently.

# **4.17 Learning Plan Integration**

Learning Plans reference:

- Subject
- Education Level
- Curriculum
- Learning Outcomes

This ensures every student's educational journey is built upon the same academic framework.

# **4.18 Homework Integration**

Homework references:

- Subject
- Topic
- Subtopic
- Lesson

This enables meaningful educational analytics and future AI assistance.

# **4.19 Instructor Integration**

Every instructor profile references the Academic Framework.

An instructor may teach:

- Multiple Subjects
- Multiple Education Levels
- Multiple Skill Levels
- Multiple Teaching Languages

Instructor profiles should never define custom academic structures outside the approved framework.

# **4.20 AI Integration Strategy**

Future AI services shall use the Academic Framework as the primary educational knowledge model.

Examples:

- Lesson summaries linked to topics.
- Homework review based on learning outcomes.
- Personalized roadmap recommendations.
- Knowledge gap analysis.
- Intelligent instructor matching.

Using a shared academic model ensures AI-generated insights remain consistent with the platform's curriculum.

# **4.21 Functional Requirements**

### **1 - Academic Category Management**

**Priority:** Critical

The system shall allow administrators to create, update, archive, and organize Academic Categories.

### **2 - Subject Management**

The system shall allow administrators to create, update, archive, and manage Subjects within Academic Categories.

### **3 - Education Level Management**

The system shall allow administrators to manage Education Levels independently of Subjects.

### **4 - Skill Level Management**

The system shall support optional Skill Levels for applicable subjects.

### **5 - Curriculum Management**

The system shall allow administrators to create and maintain versioned curricula.

### **6 - Module Management**

The system shall support curriculum modules that organize related topics.

### **7 - Topic Management**

The system shall support hierarchical Topics within curriculum modules.

### **8 - Subtopic Management**

The system shall support optional Subtopics beneath Topics.

### **9 - Learning Outcome Management**

The system shall allow administrators to define measurable Learning Outcomes associated with curriculum content.

### **10 - Regional Subject Availability**

The system shall allow administrators to configure subject availability by country.

### **11 - Teaching Language Configuration**

The system shall allow one or more teaching languages to be associated with each subject.

### **12 - Curriculum Versioning**

The system shall preserve historical curriculum versions while allowing new versions to become active.

# **4.22 Business Rules**

- Every Subject shall belong to exactly one Academic Category.
- A Curriculum shall belong to exactly one Subject and one Education Level.
- Archived academic entities shall not appear in new bookings or instructor assignments but shall remain available for historical reporting.
- Academic identifiers used by other modules shall remain stable to preserve reporting, analytics, and educational history.

# **4.23 Validation Rules**

- Subject names shall be unique within an Academic Category.
- Education Levels shall be selected from the configured master list.
- Learning Outcomes shall be associated with an active Curriculum.
- Only active Subjects and Education Levels may be assigned to instructors or learning plans.

# **4.24 Acceptance Criteria**

- Administrators can configure the complete academic hierarchy without software changes.
- Students and instructors see only active academic entities relevant to their country and permissions.
- Learning Plans, Homework, Marketplace Search, Analytics, and AI-ready services consistently use the shared Academic Framework.

# **4.25 Future Enhancements**

Potential future capabilities include:

- Multiple education boards (e.g., CBSE, ICSE, IB, GCSE, SAT, AP).
- Certification frameworks.
- Competency-based learning paths.
- Adaptive curricula.
- AI-generated curriculum suggestions.
- Curriculum import/export.
- Integration with external educational standards.

# **CHAPTER 5 - CURRICULUM, LEARNING ROADMAPS & COMPETENCY MANAGEMENT**

# **5.1 Introduction**

The Curriculum, Learning Roadmaps & Competency Management module defines how educational content is organized into structured learning journeys.

While the Academic Framework defines **what** can be taught, this module defines **how** learners should progress through educational content.

The module establishes recommended learning sequences, prerequisites, competencies, milestones, and measurable outcomes that guide students from beginner to advanced levels.

The curriculum provides a common educational structure used by instructors, students, administrators, analytics, and future AI services.

# **5.2 Objectives**

The Curriculum module shall:

- Organize subjects into structured learning paths.
- Define recommended learning progression.
- Support multiple curriculum versions.
- Track competencies and learning outcomes.
- Enable personalized learning plans.
- Support instructor lesson planning.
- Prepare the platform for AI-assisted recommendations.
- Improve student retention through structured education.

# **5.3 Scope**

This module includes:

### **Curriculum**

- Curriculum
- Curriculum Version
- Modules
- Topics
- Milestones

### **Learning Roadmaps**

- Beginner Paths
- Intermediate Paths
- Advanced Paths
- Professional Paths

### **Competencies**

- Learning Outcomes
- Knowledge Areas
- Skills
- Mastery Levels

### **Progression**

- Prerequisites
- Recommended Sequence
- Milestones
- Completion

# **5.4 Educational Philosophy**

The platform promotes **mastery-based progression** rather than random lesson selection.

Students should understand:

- Where they are.
- What they have completed.
- What comes next.
- Why it matters.
- How close they are to achieving their goals.

Learning should be visual, structured, and measurable.

# **5.5 Curriculum Structure**

Every curriculum consists of:

Curriculum

│

▼

Learning Roadmap

│

▼

Module

│

▼

Topic

│

▼

Subtopic

│

▼

Learning Outcome

│

▼

Competency

│

▼

Milestone

This structure supports both structured academic education and flexible professional learning.

# **5.6 Learning Roadmaps**

A Learning Roadmap defines the recommended progression for a subject.

Example:

Python Programming

Introduction

↓

Variables

↓

Operators

↓

Conditions

↓

Loops

↓

Functions

↓

Object-Oriented Programming

↓

Projects

Students are free to learn at their own pace, but the roadmap provides a recommended sequence.

# **5.7 Roadmap Types**

The platform supports different roadmap types.

Examples:

### **Academic Roadmap**

School curriculum.

### **Examination Roadmap**

Exam preparation.

### **Professional Roadmap**

Career-oriented learning.

### **Skill Development Roadmap**

Personal growth.

### **Certification Roadmap (Future)**

Industry certifications.

Administrators may define additional roadmap types.

# **5.8 Curriculum Versions**

Educational content evolves over time.

Therefore, curricula support versioning.

Example:

Python Programming

- Version 1.0
- Version 2.0
- Version 3.0

Historical student records remain linked to the curriculum version active when learning occurred.

New students follow the latest published version unless otherwise configured.

# **5.9 Curriculum Modules**

Modules divide curricula into logical sections.

Example:

Python Programming

Module 1

Programming Fundamentals

Module 2

Control Structures

Module 3

Functions

Module 4

Object-Oriented Programming

Module 5

Projects

Modules improve organization and reporting.

# **5.10 Learning Milestones**

Milestones represent meaningful progress checkpoints.

Examples:

Programming

- First Program Written
- Functions Mastered
- OOP Completed
- Final Project Completed

Mathematics

- Algebra Completed
- Geometry Completed
- Trigonometry Completed

Milestones motivate learners and support analytics.

# **5.11 Competency Framework**

A Competency represents a measurable skill or capability that a learner is expected to acquire.

Examples:

Programming

- Problem Solving
- Debugging
- Object-Oriented Design

English

- Reading
- Writing
- Listening
- Speaking

Mathematics

- Algebraic Reasoning
- Geometry
- Data Interpretation

Competencies provide a richer model than simply marking topics as complete.

# **5.12 Competency Levels**

The platform supports configurable competency levels.

Suggested levels:

- Beginner
- Developing
- Competent
- Advanced
- Expert

Version 1 records competency status without automated assessment.

Future AI services may assist with competency evaluation.

# **5.13 Prerequisites**

Certain topics require prerequisite knowledge.

Example:

Calculus

Prerequisites:

- Algebra
- Functions
- Trigonometry

The platform should define prerequisite relationships without preventing instructors from making informed educational decisions.

Administrators configure prerequisite mappings.

# **5.14 Learning Outcomes**

Learning Outcomes define what students should know or be able to do after completing a topic or module.

Examples:

Students should be able to:

- Build a simple Python application.
- Solve simultaneous equations.
- Explain Newton's Laws.
- Conduct a business presentation.

Learning Outcomes support:

- Curriculum quality.
- Instructor planning.
- Homework alignment.
- Future AI evaluation.

# **5.15 Progress Tracking**

Progress is measured across multiple dimensions.

Examples:

- Lessons Completed
- Topics Completed
- Homework Submitted
- Milestones Achieved
- Competencies Developed
- Learning Plan Progress

The platform should avoid reducing learning to a single percentage.

Progress should reflect educational development rather than attendance alone.

# **5.16 Instructor Guidance**

The Curriculum assists instructors by providing:

- Suggested topic sequence.
- Learning outcomes.
- Recommended milestones.
- Prerequisite visibility.

Instructors retain professional discretion and may adapt lesson delivery to the student's needs.

The curriculum is a guide, not a restriction.

# **5.17 Student Guidance**

Students should be able to view:

- Current roadmap.
- Completed modules.
- Current topic.
- Next recommended topic.
- Remaining milestones.
- Estimated learning journey.

This transparency encourages consistent engagement.

# **5.18 Analytics Integration**

Curriculum data contributes to educational analytics.

Examples:

Student

- Module completion.
- Milestone progress.
- Learning consistency.

Instructor

- Student completion rates.
- Average progression.
- Curriculum coverage.

Administrator

- Popular curricula.
- Completion statistics.
- Drop-off analysis.
- Country-wise curriculum usage.

# **5.19 AI Readiness**

Future AI services may use curriculum information to:

- Recommend next topics.
- Identify learning gaps.
- Suggest homework.
- Generate lesson summaries.
- Personalize learning plans.
- Recommend instructors.

The curriculum serves as the knowledge model for future AI capabilities.

# **5.20 Functional Requirements**

### **1 - Curriculum Management**

**Priority:** Critical

The system shall allow administrators to create, update, version, publish, archive, and retire curricula.

### **2 - Roadmap Management**

The system shall support one or more Learning Roadmaps within a curriculum.

### **3 - Module Management**

The system shall allow administrators to organize curricula into Modules.

### **4 - Milestone Management**

The system shall support configurable Learning Milestones.

### **5 - Competency Management**

The system shall allow administrators to define Competencies associated with Subjects, Modules, Topics, and Learning Outcomes.

### **6 - Prerequisite Management**

The system shall support prerequisite relationships between curriculum elements.

### **7 - Curriculum Versioning**

The system shall preserve historical curriculum versions while allowing new versions to become active.

### **8 - Student Progress Mapping**

The system shall associate student progress with the applicable curriculum version.

### **9 - Instructor Curriculum Access**

The system shall allow instructors to reference curriculum guidance while planning lessons.

### **10 - Roadmap Visibility**

Students shall be able to view their current learning roadmap and completed milestones.

# **5.21 Business Rules**

- Every curriculum belongs to one Subject and one Education Level.
- Only one curriculum version may be active for new enrollments within the same Subject, Education Level, and roadmap type at a given time.
- Historical student progress shall remain linked to the curriculum version used during learning.
- Curriculum guidance shall assist instructors without restricting their professional judgment.

# **5.22 Validation Rules**

- Curricula must contain at least one Module before publication.
- Every Module must contain at least one Topic.
- Learning Outcomes shall be associated with an active Topic or Module.
- Circular prerequisite relationships are not permitted.

# **5.23 Acceptance Criteria**

- Administrators can publish and version curricula without affecting historical records.
- Students can clearly view their learning roadmap and milestones.
- Instructors can reference curriculum guidance while retaining flexibility in lesson delivery.
- Analytics accurately reflect curriculum progression and milestone completion.

# **5.24 Future Enhancements**

The curriculum engine is designed to support future educational capabilities, including:

- Adaptive learning paths.
- Competency-based advancement.
- AI-generated study plans.
- Personalized curriculum recommendations.
- Integration with external educational standards.
- Digital certificates and credentialing.
- Automated prerequisite evaluation.
- Gamified milestone achievements.

I agree with this direction, and before we write this chapter I want to make **one major architectural improvement** that will significantly strengthen STEM Learning.

# **My Recommendation (Very Important)**

A **Learning Plan should not be created automatically**.

Instead, it should become a **living academic contract** between the student and the instructor.

Think of it as similar to a patient's treatment plan in a hospital.

It evolves.

It is reviewed.

It changes.

It records history.

This is much more valuable than simply storing:

Student

↓

Subject

↓

Tutor

↓

12 Weeks

Instead, the model becomes:

Learning Goal

│

▼

Learning Assessment

│

▼

Learning Plan

│

▼

Recurring Lessons

│

▼

Homework

│

▼

Milestones

│

▼

Progress Reviews

│

▼

Plan Adjustment

│

▼

Completion

That is what enterprise LMS platforms do.

# **STEM Learning**

## **Enterprise Software Requirements Specification (SRS) v2.0**

# **BOOK 2 - FUNCTIONAL REQUIREMENTS**

# **PART B - ACADEMIC FOUNDATION**

# **CHAPTER 6 - STUDENT LEARNING PLANS & ACADEMIC PROGRESS MANAGEMENT**

# **6.1 Introduction**

The Student Learning Plans & Academic Progress Management module defines how personalized educational journeys are created, monitored, reviewed, and completed.

Unlike lesson scheduling, which focuses on individual bookings, a Learning Plan provides long-term academic direction by combining learning goals, curriculum, instructor guidance, recurring lessons, homework, milestones, and progress reviews into a unified educational experience.

The Learning Plan is the central academic record for a student's educational journey and serves as the foundation for personalized learning, instructor collaboration, reporting, analytics, and future AI-assisted recommendations.

# **6.2 Objectives**

The Learning Plan module shall:

- Support personalized education.
- Organize long-term learning.
- Track academic progress.
- Connect lessons with learning outcomes.
- Assist instructors in planning education.
- Improve student retention.
- Support recurring learning.
- Prepare the platform for AI-assisted educational guidance.

# **6.3 Scope**

This module includes:

### **Learning Goals**

- Academic goals
- Professional goals
- Personal development goals

### **Learning Plans**

- Plan creation
- Plan ownership
- Assigned instructor
- Curriculum
- Lesson schedule
- Milestones
- Progress

### **Progress Management**

- Reviews
- Milestone completion
- Plan adjustments
- Completion

### **Educational Analytics**

- Student progress
- Instructor observations
- Learning consistency

# **6.4 Learning Plan Lifecycle**

Every Learning Plan progresses through defined stages.

Goal Created

│

▼

Initial Assessment

│

▼

Learning Plan Draft

│

▼

Instructor Assignment

│

▼

Active Learning

│

▼

Progress Review

│

▼

Plan Updated

│

▼

Completed

│

▼

Archived

Historical plans remain available for future reference.

# **6.5 Learning Goals**

Learning begins with a clearly defined objective.

Examples include:

### **Academic**

- Improve mathematics grades.
- Prepare for GCSE examinations.
- Complete university coursework.

### **Professional**

- Learn Python programming.
- Prepare for software engineering interviews.
- Improve business communication.

### **Personal**

- Learn conversational English.
- Explore photography.
- Develop creative writing skills.

A student may maintain multiple learning goals across different subjects.

# **6.6 Initial Learning Assessment**

Before or shortly after the first lesson, the instructor may perform an initial assessment.

The assessment may include:

- Existing knowledge.
- Strengths.
- Weaknesses.
- Learning pace.
- Preferred teaching approach.
- Recommended curriculum starting point.

The assessment guides the Learning Plan but does not restrict future adjustments.

# **6.7 Learning Plan**

Each Learning Plan contains:

### **Student**

- Student
- Subject
- Education Level

### **Academic**

- Curriculum
- Learning Goal
- Current Module
- Current Topic

### **Instruction**

- Assigned Instructor
- Lesson Frequency
- Lesson Duration
- Preferred Schedule

### **Progress**

- Milestones
- Learning Outcomes
- Homework
- Progress Reviews

### **Status**

- Draft
- Active
- Completed
- Archived

# **6.8 Plan Ownership**

A Learning Plan belongs to the student.

The assigned instructor contributes to and maintains the academic content while teaching the student.

If the student changes instructors:

- Historical instructor contributions remain preserved.
- The Learning Plan continues unless the student chooses to start a new one.
- The new instructor may review previous progress and continue the plan.

# **6.9 Progress Reviews**

Progress should be reviewed periodically.

Suggested review intervals:

- Every 4 lessons.
- Monthly.
- At milestone completion.
- At instructor discretion.

A review may include:

- Progress summary.
- Challenges.
- Homework quality.
- Attendance.
- Recommendations.

Progress reviews become part of the student's educational history.

# **6.10 Milestone Tracking**

Learning milestones measure meaningful achievements.

Examples:

Programming

- Variables Mastered.
- Functions Completed.
- OOP Completed.

Mathematics

- Algebra Completed.
- Geometry Completed.

Milestones help students visualize long-term progress.

# **6.11 Learning Plan Adjustments**

Learning Plans are intended to evolve.

Adjustments may occur because of:

- Faster progress.
- Additional practice needed.
- Student availability changes.
- Curriculum updates.
- Instructor recommendations.

Previous plan versions remain available for audit and educational continuity.

# **6.12 Academic Progress**

Progress should be evaluated using multiple indicators.

Examples:

- Lessons attended.
- Homework completion.
- Milestones achieved.
- Competencies developed.
- Learning consistency.
- Instructor reviews.

The platform should avoid relying on attendance alone.

# **6.13 Student Dashboard Integration**

Students should be able to view:

- Active Learning Plans.
- Current Goals.
- Current Curriculum Position.
- Upcoming Milestones.
- Homework.
- Progress Reviews.
- Estimated Completion.

# **6.14 Instructor Dashboard Integration**

Instructors should be able to view:

- Active Learning Plans.
- Student Progress.
- Upcoming Reviews.
- Homework Status.
- Curriculum Position.
- Recommended Next Topics.

# **6.15 Analytics Integration**

The Learning Plan contributes to:

Student Analytics

- Goal completion.
- Progress trends.
- Learning consistency.

Instructor Analytics

- Student improvement.
- Plan completion.
- Retention.

Administrative Analytics

- Curriculum completion.
- Popular goals.
- Student retention.
- Subject performance.

# **6.16 AI Readiness**

Future AI services may use Learning Plans to:

- Recommend study schedules.
- Identify learning gaps.
- Suggest lesson frequency.
- Recommend homework.
- Predict learning outcomes.
- Generate progress summaries.

The Learning Plan serves as the primary educational context for AI features.

# **6.17 Functional Requirements**

**Priority:** Critical

### **1 - Learning Goal Creation**

The system shall allow students to create one or more Learning Goals associated with Subjects and Education Levels.

### **2 - Learning Plan Creation**

The system shall allow a Learning Plan to be created after the student selects an instructor and begins a learning journey.

### **3 - Initial Assessment Recording**

The system shall allow instructors to record an initial learning assessment.

### **4 - Instructor Assignment**

Every active Learning Plan shall have one primary instructor at a time.

### **5 - Plan Progress Tracking**

The system shall continuously update Learning Plan progress using lessons, milestones, homework, and instructor reviews.

### **6 - Milestone Tracking**

The system shall record milestone completion within the Learning Plan.

### **7 - Plan Review**

The system shall support periodic academic reviews.

### **8 - Plan Adjustment**

The system shall allow instructors to recommend Learning Plan adjustments while preserving historical versions.

### **9 - Plan Completion**

The system shall allow Learning Plans to be completed and archived without deleting educational history.

### **10 - Multi-Plan Support**

Students may maintain multiple active Learning Plans across different Subjects.

# **6.18 Business Rules**

- A Learning Plan belongs to the student.
- Only one primary instructor may actively manage a Learning Plan at any given time.
- Learning Plan history shall remain immutable.
- Changing instructors shall not erase academic history.
- Archived Learning Plans remain available for reporting and future reference.

# **6.19 Validation Rules**

- Every Learning Plan shall reference an active Subject and Curriculum.
- Only approved instructors may manage Learning Plans.
- Completed Learning Plans shall not accept new progress updates.
- Historical versions shall remain accessible after adjustments.

# **6.20 Acceptance Criteria**

- Students can create and manage learning goals.
- Instructors can maintain Learning Plans and perform periodic progress reviews.
- Students can clearly visualize academic progress, milestones, and upcoming learning objectives.
- Historical Learning Plans remain permanently available without affecting current plans.

# **6.21 Future Enhancements**

The Learning Plan engine is designed to support:

- AI-generated learning plans.
- Adaptive study schedules.
- Competency-based progression.
- Parent visibility (future).
- Career pathway recommendations.
- Digital portfolios.
- Certificate generation.
- Learning risk prediction.
- Cross-subject learning analytics.

Excellent. I think this is one of the biggest improvements we've made to the SRS.

If we had simply written **Homework**, we would have boxed ourselves into a single feature.

Instead, **Learning Activities** becomes an extensible academic engine.

Today it supports:

- Notes
- PDF Homework

Tomorrow it can support:

- Quizzes
- Coding Labs
- Interactive Whiteboards
- AI Exercises
- Flash Cards
- Reading Assignments
- Video Lessons

without changing the architecture.

That is exactly how enterprise platforms evolve.

# **CHAPTER 7 - LEARNING ACTIVITIES, HOMEWORK & EDUCATIONAL RESOURCES**

# **7.1 Introduction**

Learning extends beyond live instructional sessions. Meaningful educational progress requires structured practice, reinforcement, revision, and access to supporting learning materials.

The Learning Activities, Homework & Educational Resources module provides the framework for assigning, distributing, completing, reviewing, and maintaining educational activities throughout a student's learning journey.

This module supports instructor-led assignments, reusable educational resources, progress tracking, and future interactive learning experiences while integrating closely with Learning Plans, Curriculum Management, Lessons, Student Progress, Analytics, and future AI services.

# **7.2 Objectives**

The module shall:

- Reinforce learning outside live lessons.
- Organize educational resources.
- Track homework completion.
- Improve student engagement.
- Support instructor feedback.
- Maintain educational history.
- Enable reusable teaching materials.
- Prepare the platform for interactive and AI-assisted learning.

# **7.3 Scope**

This module includes:

### **Learning Activities**

- Homework
- Practice assignments
- Reading tasks
- Instructor notes

### **Educational Resources**

- PDF documents
- Notes
- Reusable teaching resources

### **Student Submission**

- Homework status
- File uploads
- Submission history

### **Instructor Review**

- Feedback
- Completion
- Review history

### **Progress**

- Due dates
- Completion tracking
- Learning timeline integration

# **7.4 Learning Activity Philosophy**

Every learning activity should reinforce educational outcomes rather than simply occupy student time.

Activities should:

- Support lesson objectives.
- Align with curriculum topics.
- Reinforce competencies.
- Encourage independent learning.
- Provide measurable progress.

Learning activities remain associated with the student's educational history.

# **7.5 Learning Activity Types**

Version 1 supports:

### **Homework**

Instructor-assigned work following a lesson.

### **Instructor Notes**

Lesson notes shared with the student.

### **PDF Resources**

Supporting educational documents.

Future versions may support:

- Quizzes
- Coding exercises
- Interactive worksheets
- Flash cards
- Video lessons
- Reading assignments
- AI-generated practice
- Peer collaboration

# **7.6 Educational Resources**

Educational Resources are reusable materials that instructors can maintain and reuse across multiple students.

Examples include:

- Class notes
- Formula sheets
- Programming exercises
- Grammar guides
- Practice papers

Resources reduce repetitive work while maintaining teaching consistency.

Resources belong to instructors and remain under platform control.

# **7.7 Homework Lifecycle**

Every homework assignment progresses through defined stages.

Draft

│

▼

Assigned

│

▼

Student Viewed

│

▼

In Progress

│

▼

Submitted

│

▼

Reviewed

│

▼

Completed

Historical assignments remain available after completion.

# **7.8 Homework Assignment**

Every homework assignment should include:

### **Academic Context**

- Learning Plan
- Subject
- Curriculum
- Module
- Topic
- Lesson

### **Assignment Details**

- Title
- Description
- Due Date
- Priority
- Estimated Completion Time

### **Resources**

- Notes
- PDF Documents
- Supporting Material

### **Status**

- Assigned
- Submitted
- Reviewed
- Completed

# **7.9 Student Submission**

Students may:

- View assignments.
- Download resources.
- Upload responses.
- Mark work as submitted.
- Review instructor feedback.

Version 1 supports document-based submissions.

Future interactive submissions remain part of the roadmap.

# **7.10 Instructor Feedback**

After reviewing submitted work, instructors may provide:

- Written feedback.
- Improvement suggestions.
- Additional notes.

Future versions may support:

- Annotated documents.
- Audio feedback.
- Video feedback.
- AI-assisted review.

Feedback becomes part of the student's learning history.

# **7.11 Due Dates**

Assignments may define:

- Due Date
- Due Time
- Reminder Schedule

Late submissions remain visible within the educational record.

Administrators configure reminder behavior globally.

# **7.12 Learning Timeline Integration**

Homework contributes to the student's educational timeline.

Examples include:

- Homework Assigned
- Homework Viewed
- Homework Submitted
- Homework Reviewed
- Homework Completed

This provides a complete record of educational engagement.

# **7.13 Student Dashboard Integration**

Students should see:

- Pending Homework.
- Upcoming Due Dates.
- Recently Reviewed Homework.
- Instructor Feedback.
- Educational Resources.

The dashboard should prioritize outstanding learning activities.

# **7.14 Instructor Dashboard Integration**

Instructors should see:

- Pending Reviews.
- Upcoming Homework Deadlines.
- Recently Submitted Work.
- Frequently Used Resources.
- Student Completion Rates.

# **7.15 Resource Library**

Each instructor maintains a personal teaching resource library.

The library supports:

- Categorization.
- Search.
- Reuse across lessons.
- Version updates.

Resources remain available even after individual lessons conclude, unless intentionally archived.

# **7.16 Learning Analytics**

Homework contributes to educational analytics.

Student Analytics:

- Assignment completion rate.
- Submission consistency.
- Timeliness.
- Resource usage.

Instructor Analytics:

- Homework assigned.
- Review completion time.
- Student completion rate.

Administrative Analytics:

- Homework participation.
- Resource utilization.
- Subject engagement.
- Country-level educational activity.

# **7.17 AI Readiness**

Future AI capabilities may include:

- Automatic homework generation.
- Personalized practice recommendations.
- AI-assisted feedback.
- Knowledge gap detection.
- Suggested educational resources.
- Study reminders.
- Learning habit analysis.

The module should expose structured educational context to future AI services.

# **7.18 Functional Requirements**

### **1 - Learning Activity Creation**

**Priority:** Critical

The system shall allow instructors to create learning activities linked to a Learning Plan and Lesson.

### **2 - Homework Assignment**

The system shall allow instructors to assign homework with due dates, descriptions, and supporting resources.

### **3 - Educational Resource Attachment**

The system shall allow notes and PDF documents to be attached to learning activities.

### **4 - Student Homework Access**

Students shall be able to view, download, and manage assigned learning activities.

### **5 - Homework Submission**

The system shall allow students to upload homework responses before or after the due date, subject to platform policy.

### **6 - Instructor Feedback**

The system shall allow instructors to provide structured written feedback on submitted homework.

### **7 - Homework Status Tracking**

The system shall track homework throughout its lifecycle.

### **8 - Resource Library**

The system shall allow instructors to maintain reusable educational resources.

### **9 - Learning Timeline Integration**

Homework events shall automatically update the student's educational timeline.

### **10 - Dashboard Integration**

Homework summaries shall appear on student and instructor dashboards.

# **7.19 Business Rules**

- Every homework assignment shall reference a Lesson or Learning Plan.
- Educational resources remain owned by the platform and managed by the instructor.
- Homework history shall remain available after lesson completion.
- Students may resubmit homework only if permitted by instructor or platform policy.

# **7.20 Validation Rules**

- Assignments must contain a title and academic context before publication.
- Only approved instructors may assign homework.
- Students may submit homework only for assignments available to them.
- Uploaded files shall comply with configured format and size restrictions.

# **7.21 Acceptance Criteria**

- Instructors can create, assign, and review homework without administrative assistance.
- Students can access resources, submit homework, and review instructor feedback.
- Homework activity is reflected in dashboards, learning timelines, and analytics.
- Reusable educational resources can be shared across multiple lessons without duplication.

# **7.22 Future Enhancements**

The learning activity framework is designed to support:

- Interactive quizzes.
- Coding environments.
- Whiteboard exercises.
- Flashcards.
- AI-generated practice.
- Video assignments.
- Peer review.
- Gamified learning activities.
- Plagiarism detection.
- Automated grading.
- Adaptive homework based on student performance.

# **PART B Completion Summary**

With Chapters 4-7 complete, the Academic Foundation now defines:

- **Academic Framework** - what can be taught.
- **Curriculum & Roadmaps** - how knowledge is structured.
- **Learning Plans** - how an individual student progresses.
- **Learning Activities & Resources** - how learning is reinforced outside live lessons.

PART C

# **PART C - MARKETPLACE & DISCOVERY**

# **CHAPTER 8 - DISCOVERY, SEARCH & RECOMMENDATION ENGINE**

# **8.1 Introduction**

The Discovery, Search & Recommendation Engine is responsible for helping students efficiently identify the most suitable instructors, subjects, and learning opportunities.

Rather than presenting a static list of instructors, the platform shall provide an intelligent discovery experience that combines structured search, advanced filtering, personalized recommendations, and localized marketplace content.

The discovery engine serves as the primary entry point into the marketplace and directly influences student engagement, lesson bookings, instructor utilization, and long-term retention.

This module integrates with the Academic Framework, Instructor Profiles, Availability Engine, Learning Plans, Localization, Analytics, and future AI-powered recommendation services.

# **8.2 Objectives**

The Discovery Engine shall:

- Enable students to find suitable instructors quickly.
- Provide accurate and relevant search results.
- Support advanced filtering.
- Promote personalized discovery.
- Respect country-specific availability and pricing.
- Increase demo-to-paid conversion.
- Improve instructor utilization.
- Provide a scalable foundation for AI-assisted recommendations.

# **8.3 Scope**

This module includes:

### **Discovery**

- Home recommendations
- Featured instructors
- Trending subjects
- Recently viewed instructors
- Favorite instructors

### **Search**

- Global search
- Subject search
- Instructor search
- Keyword search

### **Filtering**

- Subject
- Education Level
- Country
- Language
- Teaching Language
- Availability
- Lesson Duration
- Rating
- Experience
- Verification
- Price Range
- Demo Availability

### **Recommendation**

- Personalized recommendations
- Popular instructors
- New instructors
- Similar instructors
- Recently viewed
- AI recommendations (Future)

# **8.4 Discovery Philosophy**

The platform should minimize the effort required for a student to find the right instructor.

Discovery should focus on:

- Relevance.
- Simplicity.
- Personalization.
- Educational fit.
- Availability.
- Trust.

Search results should prioritize helping students make informed educational decisions rather than maximizing the number of visible instructors.

# **8.5 Student Discovery Journey**

A typical discovery journey is:

Visit Marketplace

│

▼

Browse Subjects

│

▼

Search

│

▼

Apply Filters

│

▼

Compare Instructors

│

▼

View Public Profile

│

▼

Check Availability

│

▼

Book Demo

│

▼

Book Paid Lessons

The platform should minimize unnecessary navigation between these stages.

# **8.6 Global Search**

The platform shall provide a unified search experience capable of locating:

- Subjects
- Instructors
- Academic Categories
- Education Levels
- Teaching Languages

Search should tolerate common spelling variations and partial matches.

Future versions may introduce semantic and AI-assisted search.

# **8.7 Search Categories**

Students should be able to search by:

### **Subject**

Examples:

- Mathematics
- Python
- English

### **Instructor**

Search by instructor name.

### **Topic (Future)**

Examples:

- Algebra
- Loops
- Grammar

### **Keyword**

Free-text search across instructor profiles, biographies, and subject information.

# **8.8 Marketplace Filters**

Students should be able to combine multiple filters simultaneously.

Supported filters include:

### **Academic**

- Subject
- Academic Category
- Education Level
- Skill Level

### **Instructor**

- Experience
- Rating
- Verified
- Teaching Language
- Native Language (Optional)

### **Scheduling**

- Available Today
- Available This Week
- Demo Available
- Lesson Duration
- Time Zone Compatibility

### **Financial**

- Student Price Range
- Currency (derived from student country)

### **Regional**

- Student Country
- Instructor Country (optional filter)

# **8.9 Sorting**

Students should be able to sort results by:

- Recommended
- Highest Rating
- Most Booked
- Fastest Response
- Lowest Price
- Highest Price
- Newest Instructor
- Most Experienced

The default sort should prioritize recommendation quality rather than chronology.

# **8.10 Recommendation Engine**

The platform should provide recommendation sections such as:

- Recommended for You
- Popular Instructors
- Trending Subjects
- Continue Learning
- Recently Viewed
- Favorite Instructors
- Similar Instructors
- New Instructors

Recommendations should evolve as students interact with the platform.

# **8.11 Recommendation Signals**

Recommendations may consider:

### **Student Context**

- Learning Goals
- Favorite Subjects
- Education Level
- Country
- Language
- Previous Bookings
- Demo History

### **Instructor Context**

- Subjects
- Availability
- Ratings
- Experience
- Response Time
- Lesson Completion Rate
- Student Retention

### **Marketplace Context**

- Popularity
- Seasonal demand
- Regional demand
- New instructor visibility

Version 1 uses configurable business rules. Future versions may incorporate AI-driven ranking.

# **8.12 Featured Instructors**

Administrators may feature instructors based on business strategy.

Featured status should be configurable and time-bound.

Featured instructors should still satisfy marketplace quality standards.

# **8.13 New Instructor Visibility**

New instructors should receive controlled marketplace exposure to encourage early bookings.

Visibility should balance fairness with marketplace quality.

# **8.14 Favorites**

Students may save instructors for future consideration.

Favorites support:

- Quick access.
- Availability notifications.
- Personalized recommendations.
- Marketing campaigns.

# **8.15 Recently Viewed**

The platform should maintain a history of recently viewed instructors for each student.

Students should be able to return to previously explored profiles easily.

# **8.16 Search Analytics**

The platform should capture anonymous marketplace analytics, including:

- Popular search terms.
- Search frequency.
- Zero-result searches.
- Filter usage.
- Conversion from search to booking.

These insights help improve curriculum planning, instructor recruitment, and marketplace optimization.

# **8.17 Localization**

Search results should respect:

- Student country.
- Billing currency.
- Supported subjects.
- Regional availability.
- Language preferences.

Students should not see unavailable offerings for their configured region unless explicitly browsing globally.

# **8.18 AI Readiness**

Future AI capabilities may include:

- Natural language search.
- Personalized instructor recommendations.
- Learning pathway recommendations.
- Skill gap identification.
- Predictive instructor matching.
- Dynamic ranking based on learning outcomes.

The discovery engine should expose structured data suitable for future AI models.

# **8.19 Functional Requirements**

### **1 - Global Search**

**Priority:** Critical

The system shall provide a unified search interface for discovering instructors, subjects, and academic content.

### **2 - Multi-Filter Search**

The system shall allow students to combine multiple filters without requiring separate searches.

### **3 - Country-Aware Results**

The system shall prioritize instructors, pricing, and offerings applicable to the student's configured country.

### **4 - Recommendation Sections**

The system shall display configurable recommendation sections on the marketplace and dashboard.

### **5 - Favorites**

Students shall be able to add and remove instructors from their favorites list.

### **6 - Recently Viewed**

The system shall maintain a personalized list of recently viewed instructor profiles.

### **7 - Featured Instructors**

Administrators shall be able to feature instructors within configurable marketplace sections.

### **8 - Search Suggestions**

The system shall provide relevant search suggestions while students enter search terms.

### **9 - Search Analytics**

The system shall record search activity for reporting and marketplace optimization.

### **10 - Recommendation Configuration**

Administrators shall be able to configure recommendation strategies, featured content, and ranking priorities.

# **8.20 Business Rules**

- Only approved and active instructors shall appear in marketplace search results.
- Search results shall respect regional availability and localization policies.
- Featured placement shall not bypass instructor suspension or verification requirements.
- Students shall only see pricing applicable to their assigned billing country.

# **8.21 Validation Rules**

- At least one search criterion or browse context shall be present before executing a search.
- Filters shall only present active Academic Framework entities.
- Recommendation sections shall not display archived or unavailable content.

# **8.22 Acceptance Criteria**

- Students can discover instructors using search, filters, and recommendations.
- Marketplace results reflect localization, pricing, and instructor availability.
- Search activity contributes to marketplace analytics without exposing personal data.
- Recommendation sections update according to administrator configuration and student activity.

# **8.23 Future Enhancements**

The Discovery Engine is designed to support:

- Conversational AI search.
- Voice search.
- Image-assisted search (where applicable).
- Personalized ranking based on learning outcomes.
- Real-time popularity signals.
- Collaborative filtering ("Students with similar goals also booked...").
- Learning-path recommendations.
- Seasonal discovery campaigns.
- Cross-subject recommendations.

# **CHAPTER 9 - INSTRUCTOR MARKETPLACE, PUBLIC PROFILES & TRUST SYSTEM**

# **9.1 Introduction**

The Instructor Marketplace, Public Profiles & Trust System module enables students to evaluate instructors before making booking decisions.

This module combines professional instructor information, educational qualifications, teaching expertise, verification status, student reviews, trust indicators, and localized pricing into a transparent marketplace experience.

Every instructor profile serves as a public representation of the instructor while adhering to platform privacy, branding, and governance policies.

The module is designed to maximize trust, improve conversion from profile views to bookings, and support long-term marketplace growth.

# **9.2 Objectives**

The module shall:

- Present professional instructor profiles.
- Build trust through verification and transparency.
- Showcase teaching expertise.
- Display localized pricing and availability.
- Improve demo and paid lesson conversions.
- Support SEO and organic discovery.
- Protect instructor and student privacy.
- Provide consistent marketplace presentation.

# **9.3 Scope**

This module includes:

### **Public Instructor Profiles**

- Personal introduction
- Professional information
- Teaching experience
- Education
- Languages
- Subjects
- Education levels
- Teaching philosophy
- Introduction video

### **Trust Indicators**

- Verification badge
- Reviews
- Ratings
- Lesson statistics
- Response time
- Completion rate

### **Marketplace Actions**

- Book Demo
- Book Lesson
- Add to Favorites
- Join Waitlist
- Share Profile

### **SEO**

- Public URLs
- Structured metadata
- Search indexing

# **9.4 Marketplace Philosophy**

Instructor profiles should answer the questions every student naturally asks:

- Is this instructor qualified?
- Can they teach my subject?
- Can they teach at my level?
- Do other students recommend them?
- Are they available when I need them?
- Can I trust them?

The profile should encourage informed decisions rather than relying solely on promotional content.

# **9.5 Public Profile Structure**

Each instructor profile contains the following sections.

Hero Section

│

▼

Professional Overview

│

▼

Teaching Expertise

│

▼

Subjects & Levels

│

▼

Languages

│

▼

Experience

│

▼

Education

│

▼

Certificates

│

▼

Teaching Philosophy

│

▼

Reviews & Ratings

│

▼

Availability Summary

│

▼

Call to Action

# **9.6 Hero Section**

The top section of every profile should include:

- Profile photograph
- Display name
- Professional headline
- Verification badge
- Average rating
- Total reviews
- Languages taught
- Country
- Response time
- Demo lesson availability

The primary call-to-action should allow students to:

- Book a Free Demo
- Book a Paid Lesson
- Add to Favorites

# **9.7 Professional Overview**

The instructor provides:

- Biography
- Years of teaching experience
- Areas of specialization
- Professional achievements
- Teaching methodology

The biography should be concise, authentic, and professionally presented.

# **9.8 Teaching Expertise**

Profiles shall clearly display:

- Subjects taught
- Education levels
- Skill levels
- Teaching languages
- Curriculum familiarity (where applicable)

This information must reference the Academic Framework and not free-text values.

# **9.9 Experience & Qualifications**

The profile may display:

- Total teaching experience
- Previous institutions
- Professional roles
- Educational qualifications
- Certifications
- Awards (optional)

Administrators determine which qualification types require verification before being shown publicly.

# **9.10 Teaching Philosophy**

Instructors may describe:

- Teaching approach
- Classroom expectations
- Learning methodology
- Student engagement style

This helps students choose an instructor whose teaching style aligns with their learning preferences.

# **9.11 Introduction Video**

Instructors may upload a short introduction video.

The video should:

- Introduce the instructor.
- Describe teaching experience.
- Explain teaching style.
- Welcome prospective students.

Administrators may review or reject videos that violate platform policies.

# **9.12 Reviews & Ratings**

Public profiles display:

- Average rating
- Total reviews
- Recent reviews
- Verified lesson reviews only

Reviews shall be linked to completed lessons to ensure authenticity.

Administrators retain moderation authority in accordance with platform policies.

# **9.13 Trust System**

The platform shall display trust indicators, including:

- Verified Instructor badge.
- Identity verified.
- Qualification verified (where applicable).
- Response time.
- Lesson completion rate.
- Student retention indicator (future).
- Active instructor status.

Trust indicators help students make informed decisions while maintaining consistency across the marketplace.

# **9.14 Localized Pricing**

Student-facing lesson prices shall be displayed according to:

- Student country.
- Billing currency.
- Lesson duration.
- Subject.
- Education level.

Instructor compensation remains confidential and is never displayed publicly.

Where multiple lesson durations are available, prices should be presented clearly for comparison.

# **9.15 Availability Summary**

The profile should display:

- Earliest available lesson.
- Available today (where applicable).
- Demo availability.
- Upcoming open slots.

Detailed scheduling is handled by the Availability Engine.

# **9.16 Marketplace Actions**

Students may perform the following actions from the profile:

- Book Free Demo.
- Book Paid Lesson.
- Add to Favorites.
- Join Waitlist.
- Share Profile.
- Report Profile (future).

The profile should minimize the number of steps required to start a booking.

# **9.17 Privacy Protection**

Public profiles shall never expose:

- Personal email address.
- Phone number.
- Residential address.
- Government identification.
- Payment information.
- Internal administrative notes.
- Instructor compensation.

Communication occurs exclusively through the platform.

# **9.18 SEO Strategy**

Every approved instructor receives a dedicated public profile URL.

SEO should include:

- Search-friendly URL.
- Meta title.
- Meta description.
- Structured educational metadata.
- Open Graph metadata.
- Canonical URL.

Profiles should remain indexable while active unless administrators explicitly disable public indexing.

# **9.19 Analytics Integration**

Profile analytics include:

Instructor:

- Profile views.
- Demo booking conversion.
- Paid booking conversion.
- Favorite additions.
- Search impressions.

Administrator:

- Top-performing instructors.
- Profile engagement.
- Marketplace conversion.
- Country-wise performance.

Students do not have access to marketplace analytics beyond their own interactions.

# **9.20 AI Readiness**

Future AI services may enhance profiles through:

- AI-generated profile summaries.
- Personalized instructor recommendations.
- Teaching style matching.
- Learning goal compatibility scoring.
- Automatic FAQ generation.
- Profile optimization suggestions.

All AI-generated content should remain reviewable and subject to administrator policies.

# **9.21 Functional Requirements**

### **1 - Public Profile Publication**

**Priority:** Critical

The system shall publish a public instructor profile only after the instructor has completed the required onboarding and received administrative approval.

### **2 - Professional Information Display**

The system shall display approved professional information from the instructor's profile in a standardized format.

### **3 - Academic Framework Integration**

Subjects, education levels, skill levels, and teaching languages displayed on public profiles shall reference the centralized Academic Framework.

### **4 - Trust Indicators**

The system shall display approved trust indicators associated with each instructor.

### **5 - Localized Pricing**

The system shall display lesson pricing based on the student's billing country and applicable pricing configuration.

### **6 - Introduction Video**

The system shall allow instructors to publish an approved introduction video on their public profile.

### **7 - Reviews**

The system shall display reviews from completed lessons in accordance with review moderation policies.

### **8 - Favorites**

Students shall be able to add or remove instructors from their favorites directly from the public profile.

### **9 - Public SEO**

The system shall generate search-engine-friendly public profile pages for eligible instructors.

### **10 - Marketplace Actions**

The system shall provide clear actions to book a demo, book a lesson, or join a waitlist where applicable.

# **9.22 Business Rules**

- Only approved, active instructors may appear in the public marketplace.
- Only verified lesson reviews shall be displayed publicly.
- Student-facing pricing shall follow the localization and country pricing policies defined in Book 1.
- Instructor compensation, internal notes, and administrative information shall never appear on public profiles.

# **9.23 Validation Rules**

- Public profiles must include all mandatory professional information before publication.
- Only approved introduction videos may be displayed publicly.
- Academic information displayed on profiles shall reference active entities from the Academic Framework.
- Archived or suspended instructors shall not appear in marketplace search results.

# **9.24 Acceptance Criteria**

- Students can evaluate instructors using verified professional information, reviews, and trust indicators.
- Marketplace profiles display localized pricing and relevant availability without exposing confidential information.
- Students can initiate demo bookings, paid bookings, favorites, or waitlist actions directly from the profile.
- Public instructor profiles are optimized for search engines while remaining consistent with platform governance.

# **9.25 Future Enhancements**

The marketplace profile system is designed to support:

- AI-generated teaching highlights.
- Video testimonials.
- Instructor portfolio sections.
- Published articles and educational content.
- Digital badges and achievements.
- Subject-specific endorsements.
- Student success stories.
- Live profile activity indicators.
- Multi-language public profiles.
- Community Q&A.

# **CHAPTER 10 - AVAILABILITY & SCHEDULING ENGINE**

# **10.1 Introduction**

The Availability & Scheduling Engine defines how instructor availability is created, maintained, calculated, displayed, reserved, blocked, and synchronized across the STEM Learning platform.

This module ensures that students can only book valid and available lesson slots while preventing double-booking, timezone confusion, scheduling conflicts, excessive instructor workload, and operational errors.

The Availability & Scheduling Engine is a foundational platform capability used by Demo Booking, Paid Booking, Recurring Booking, Meeting Management, Waitlist, Notifications, Instructor Dashboard, Student Dashboard, and Analytics.

The system shall treat availability as a dynamic scheduling model rather than a static list of time slots.

# **10.2 Purpose**

The purpose of this module is to provide a reliable and automated scheduling system that allows instructors to define when they are available and allows students to book only valid lesson slots.

The engine must support:

- Instructor-defined working hours
- Recurring weekly schedules
- Daily availability
- Breaks
- Buffer time
- Vacation mode
- Blocked dates
- Booking notice periods
- Maximum advance booking windows
- Demo and paid lesson availability
- Recurring lesson scheduling
- Waitlist notifications
- Timezone-safe scheduling

# **10.3 Business Objectives**

The Availability & Scheduling Engine shall support the following business objectives:

- Allow instructors to self-manage their teaching availability.
- Minimize administrative intervention in scheduling.
- Prevent double-bookings.
- Improve student booking experience.
- Support recurring learning.
- Support global time zones.
- Improve instructor workload control.
- Enable waitlist-based recovery when slots are unavailable.
- Support accurate reporting and analytics.
- Prepare the system for future AI schedule optimization.

# **10.4 Scope**

This chapter covers:

## **Instructor Availability**

- Weekly schedule
- Daily schedule
- Recurring availability
- Break periods
- Buffer periods
- Vacation mode
- Blocked dates
- Temporary availability changes

## **Scheduling Rules**

- Minimum booking notice
- Maximum advance booking window
- Lesson duration compatibility
- Booking conflict detection
- Recurring booking availability
- Timezone conversion

## **Marketplace Availability**

- Available slots shown to students
- Earliest available slot
- Available today
- Available this week
- Demo availability
- Paid lesson availability

## **Waitlist Integration**

- No-slot waitlist
- Preferred time preferences
- Slot availability notification

## **Administrative Configuration**

- Global availability rules
- Country-specific scheduling behavior
- Instructor-level override policies

# **10.5 Core Scheduling Concepts**

## **Availability Rule**

An Availability Rule defines when an instructor is generally available.

Example:

- Monday: 09:00 AM - 01:00 PM
- Wednesday: 04:00 PM - 08:00 PM
- Saturday: 10:00 AM - 02:00 PM

Availability rules may repeat weekly.

## **Availability Exception**

An Availability Exception modifies normal availability for a specific date or period.

Examples:

- Instructor unavailable on 15 August.
- Instructor available extra hours on Sunday.
- Instructor blocks time for personal reasons.
- Instructor takes vacation for one week.

## **Bookable Slot**

A Bookable Slot is a calculated time slot that satisfies all scheduling rules and can be shown to students.

A slot is bookable only if:

- Instructor is active.
- Instructor is not in vacation mode.
- Slot is inside working hours.
- Slot does not overlap with breaks.
- Slot does not conflict with existing bookings.
- Slot satisfies lesson duration.
- Slot satisfies minimum notice.
- Slot is within maximum advance booking period.
- Slot is available for the selected lesson type.

## **Reserved Slot**

A Reserved Slot is temporarily held while a student completes booking or payment.

The reservation prevents another student from booking the same slot during checkout.

Reserved slots expire automatically if the booking is not completed within the configured time.

## **Blocked Slot**

A Blocked Slot is unavailable for booking.

Reasons may include:

- Existing booking
- Instructor break
- Vacation
- Manual block
- Public holiday
- Administrative block
- Temporary scheduling restriction

# **10.6 Instructor Weekly Schedule**

Instructors shall be able to define recurring weekly availability.

The weekly schedule may include:

- Day of week
- Start time
- End time
- Lesson type availability
- Break periods
- Teaching capacity
- Active/inactive status

Example:

| **Day**   | **Time**    | **Availability** |
| --------- | ----------- | ---------------- |
| Monday    | 09:00-13:00 | Demo + Paid      |
| ---       | ---         | ---              |
| Monday    | 15:00-19:00 | Paid Only        |
| ---       | ---         | ---              |
| Wednesday | 10:00-14:00 | Demo + Paid      |
| ---       | ---         | ---              |
| Saturday  | 11:00-16:00 | Paid Only        |
| ---       | ---         | ---              |

The system shall use this schedule to generate bookable slots.

# **10.7 Lesson Duration Support**

Lesson durations are configured globally by the administrator.

Supported examples:

- 30 minutes
- 45 minutes
- 60 minutes
- 90 minutes
- 120 minutes

Instructors may select which configured durations they are willing to teach.

A student can only book a duration supported by:

- Platform settings
- Subject pricing configuration
- Instructor teaching configuration
- Available slot length

# **10.8 Break Management**

Instructors may define breaks within their working schedule.

Examples:

- Lunch break
- Prayer break
- Personal break
- Administrative break

Rules:

- Breaks are not bookable.
- Breaks may be recurring.
- Breaks may be date-specific.
- Breaks must not overlap with confirmed bookings.
- Breaks must be considered during slot generation.

# **10.9 Buffer Time**

Buffer time is a gap between lessons to prevent back-to-back exhaustion and allow preparation.

Example:

- Lesson duration: 60 minutes
- Buffer: 15 minutes
- Next lesson can start after 75 minutes

Buffer time may be configured:

- Globally
- By country
- By instructor
- By lesson type

The system shall apply the most specific applicable buffer rule.

# **10.10 Minimum Booking Notice**

Minimum booking notice defines how soon before a lesson students are allowed to book.

Example:

If minimum booking notice is 2 hours, a student cannot book a lesson starting in the next 2 hours.

Minimum notice protects instructors from last-minute bookings.

This may be configured:

- Globally
- By country
- By instructor
- By lesson type

# **10.11 Maximum Advance Booking Window**

Maximum advance booking window defines how far into the future students can book.

Examples:

- 14 days
- 30 days
- 60 days

This prevents students from booking too far ahead when instructor schedules may change.

Recurring bookings may reserve multiple future slots according to configured limits.

# **10.12 Vacation Mode**

Vacation mode allows an instructor to pause availability temporarily without losing profile status or ranking history.

When vacation mode is active:

- New bookings are disabled.
- Existing bookings remain unless cancelled or rescheduled according to policy.
- Public profile may display limited availability notice.
- Waitlist may remain active if allowed.
- Instructor ranking history is preserved.
- Instructor analytics remain intact.

Vacation mode may include:

- Start date
- End date
- Reason
- Optional message to students
- Whether existing lessons should remain active

# **10.13 Blocked Dates & Time Off**

Instructors may block specific dates or time ranges.

Examples:

- Medical appointment
- Family event
- Personal leave
- Exam duty
- Travel

Blocked dates override weekly availability.

The system shall ensure that blocked dates do not allow new bookings.

If a block conflicts with existing bookings, the instructor should be warned and guided to reschedule or cancel according to platform rules.

# **10.14 Public Holidays**

The platform may support public holidays in future versions.

Public holidays may be configured by country.

When enabled:

- Country-specific holidays may affect instructor availability.
- Instructors may choose whether to teach on holidays.
- Admin may define default holiday behavior.

Version 1 may keep holiday handling manual through blocked dates.

# **10.15 Demo Availability**

Demo lessons are free and student-selected.

Demo availability follows instructor availability but may have additional restrictions.

Rules:

- Demo slots occupy instructor schedule.
- Demo duration is configurable.
- One student may book one free demo per instructor.
- Instructor may define whether a time block supports demo lessons.
- Demo slots must not overlap with paid lessons.
- Demo bookings generate meeting links automatically.

# **10.16 Paid Lesson Availability**

Paid lessons use the same availability engine but require:

- Valid student pricing
- Valid instructor status
- Valid subject and education level
- Valid lesson duration
- Sufficient wallet balance or successful payment
- No schedule conflict

Paid lesson availability should be shown clearly after subject, duration, and instructor are selected.

# **10.17 Recurring Availability**

Recurring bookings require availability across multiple future dates.

The system shall evaluate every occurrence before confirming a recurring schedule.

Supported recurring patterns for Version 1:

- Daily
- Weekly

Examples:

- Every Monday at 5:00 PM
- Every Monday, Wednesday, and Friday at 6:00 PM
- Daily at 7:00 PM

A recurring schedule is valid only if all required occurrences are available or the system provides clear handling for unavailable occurrences.

# **10.18 Recurring Booking Conflict Handling**

If one or more recurring occurrences are unavailable, the system may:

- Reject the recurring booking.
- Show unavailable dates.
- Allow student to choose alternate slots.
- Create only available occurrences if platform policy allows.
- Suggest similar available schedules.

The recommended Version 1 behavior is:

- Show unavailable occurrences clearly.
- Require the student to choose alternatives before confirmation.
- Do not silently skip lessons.

# **10.19 Timezone Strategy**

All availability and bookings must follow timezone-safe rules.

Core principles:

- Instructor defines availability in their local timezone.
- Student views slots in their own local timezone.
- System stores confirmed booking times in UTC.
- Admin may view both local and UTC times where necessary.

The system must handle timezone conversion consistently across:

- Search results
- Public profiles
- Booking pages
- Student dashboard
- Instructor dashboard
- Notifications
- Calendar invites
- Meeting creation

# **10.20 Daylight Saving Time**

For countries with daylight saving time, the system shall ensure that recurring availability remains understandable and consistent.

The instructor's local schedule should remain based on their local clock time.

Example:

If an instructor teaches every Monday at 6:00 PM local time, the lesson should remain Monday 6:00 PM for the instructor even when UTC offset changes due to daylight saving.

Students may see the converted time shift according to their own timezone.

# **10.21 Slot Generation**

The system shall calculate available slots dynamically based on:

- Instructor weekly schedule
- Availability exceptions
- Breaks
- Buffer time
- Existing bookings
- Reserved slots
- Lesson duration
- Booking notice
- Advance booking window
- Vacation mode
- Instructor status
- Country-specific rules

Generated slots shall only include times that are actually bookable.

# **10.22 Slot Reservation During Checkout**

When a student starts booking a slot, the system shall temporarily reserve the slot.

Purpose:

- Prevent duplicate booking during checkout
- Protect payment flow
- Improve booking reliability

Reservation rules:

- Reservation duration is configurable.
- Expired reservations are released automatically.
- Confirmed bookings permanently block the slot.
- Failed payments release the slot unless retry policy applies.

# **10.23 Conflict Detection**

The scheduling engine shall prevent conflicts across:

- Demo lessons
- Paid lessons
- Recurring lessons
- Breaks
- Vacation
- Blocked slots
- Reserved slots
- Meeting extensions where applicable

Conflict detection shall occur before:

- Slot display
- Booking confirmation
- Rescheduling
- Recurring booking confirmation
- Availability update

# **10.24 Availability Update Rules**

Instructors may update future availability.

Rules:

- Updates must not silently cancel confirmed bookings.
- If changes affect future bookings, the system must notify the instructor.
- Confirmed bookings remain valid unless explicitly rescheduled or cancelled.
- Availability changes are recorded in activity history.
- Frequent disruptive changes may affect instructor analytics.

# **10.25 Student Availability View**

Students should see simple and clear availability.

The system should display:

- Available dates
- Available time slots
- Local timezone
- Lesson duration
- Demo availability
- Paid availability
- Earliest available slot
- Fully booked indicators
- Waitlist option when unavailable

Students should not see internal instructor scheduling rules.

# **10.26 Instructor Availability View**

Instructors should see a complete calendar view including:

- Working hours
- Available slots
- Confirmed lessons
- Demo lessons
- Paid lessons
- Recurring lessons
- Breaks
- Vacation
- Blocked time
- Pending reservations
- Upcoming schedule

The instructor interface should make it easy to understand availability status.

# **10.27 Admin Availability View**

Administrators should be able to view instructor availability for operational support.

Admin visibility may include:

- Instructor calendar
- Existing bookings
- Availability rules
- Blocked slots
- Vacation status
- Conflict warnings
- Waitlist demand
- Booking history

Admins may override availability only according to governance and audit policies.

# **10.28 Waitlist Integration**

When no suitable slot is available, students may join the instructor waitlist.

The waitlist may collect:

- Preferred days
- Preferred time range
- Subject
- Lesson duration
- Demo or paid preference
- Recurring preference

When matching availability becomes available:

- Eligible students are notified.
- Notification priority follows platform configuration.
- Booking remains first-come, first-served unless future rules introduce priority.

# **10.29 Availability Analytics**

The system should collect scheduling analytics.

Instructor analytics:

- Available hours
- Booked hours
- Utilization rate
- Demo availability
- Paid lesson availability
- Cancellation impact
- Vacation time

Admin analytics:

- High-demand instructors
- Fully booked instructors
- Low availability instructors
- Waitlist pressure
- Country-wise scheduling demand
- Subject-wise scheduling demand

# **10.30 Functional Requirements**

## **Availability Setup**

### **1 - Weekly Availability Creation**

**Priority:** Critical

The system shall allow approved instructors to create recurring weekly availability.

### **2 - Availability Editing**

**Priority:** Critical

The system shall allow instructors to edit future availability without affecting historical bookings.

### **3 - Availability Deactivation**

**Priority:** High

The system shall allow instructors to deactivate availability rules for future dates.

### **4 - Multiple Availability Windows**

**Priority:** Critical

The system shall allow instructors to define multiple availability windows within the same day.

Example:

- 09:00-12:00
- 16:00-20:00

### **5 - Lesson Type Availability**

**Priority:** High

The system shall allow availability windows to support demo lessons, paid lessons, or both.

## **Breaks & Buffers**

### **6 - Break Management**

**Priority:** High

The system shall allow instructors to define recurring and date-specific break periods.

### **7 - Buffer Time Application**

**Priority:** Critical

The system shall apply configured buffer time between lessons when generating bookable slots.

### **8 - Break Conflict Prevention**

**Priority:** Critical

The system shall prevent bookable slots from overlapping with configured breaks.

## **Blocking & Vacation**

### **9 - Blocked Time**

**Priority:** Critical

The system shall allow instructors to block specific dates or time ranges.

### **10 - Vacation Mode**

**Priority:** High

The system shall allow instructors to activate vacation mode for a defined period.

### **11 - Vacation Visibility**

**Priority:** Medium

The system shall communicate instructor unavailability appropriately to students without exposing private reasons.

### **12 - Existing Booking Warning**

**Priority:** Critical

The system shall warn instructors when availability changes may affect confirmed bookings.

## **Slot Generation**

### **13 - Dynamic Slot Generation**

**Priority:** Critical

The system shall dynamically generate bookable slots using all applicable scheduling rules.

### **14 - Duration-Based Slot Generation**

**Priority:** Critical

The system shall generate slots based on the selected lesson duration.

### **15 - Timezone Conversion**

**Priority:** Critical

The system shall display slots to students and instructors in their respective local timezones.

### **16 - UTC Storage**

**Priority:** Critical

The system shall store confirmed booking start and end times in UTC.

### **17 - Minimum Notice Enforcement**

**Priority:** Critical

The system shall prevent bookings that violate the configured minimum booking notice.

### **18 - Advance Booking Limit**

**Priority:** High

The system shall prevent bookings beyond the configured maximum advance booking window.

## **Conflict Detection**

### **19 - Booking Conflict Detection**

**Priority:** Critical

The system shall prevent bookings that overlap with confirmed lessons.

### **20 - Reservation Conflict Detection**

**Priority:** Critical

The system shall prevent multiple students from reserving the same slot simultaneously.

### **21 - Recurring Conflict Detection**

**Priority:** Critical

The system shall validate all occurrences of a recurring schedule before confirmation.

## **Student View**

### **22 - Student Slot Display**

**Priority:** Critical

The system shall display only valid and bookable slots to students.

### **23 - Earliest Available Slot**

**Priority:** High

The system shall display the instructor's earliest available slot where applicable.

### **24 - Fully Booked State**

**Priority:** High

The system shall clearly indicate when an instructor has no available slots.

### **25 - Waitlist Option**

**Priority:** High

The system shall offer waitlist joining when no suitable availability exists.

## **Instructor View**

### **26 - Instructor Calendar**

**Priority:** Critical

The system shall provide instructors with a calendar view of availability, bookings, breaks, and blocked periods.

### **27 - Availability Summary**

**Priority:** High

The system shall show instructors a summary of weekly available hours and booked hours.

### **28 - Upcoming Schedule**

**Priority:** Critical

The system shall display upcoming demo and paid lessons in the instructor dashboard.

## **Admin View**

### **29 - Admin Availability Oversight**

**Priority:** High

Administrators shall be able to view instructor availability for operational support.

### **30 - Admin Override**

**Priority:** Medium

Administrators may override availability only when permitted by role permissions and audit policy.

## **Recurring Scheduling**

### **31 - Daily Recurring Availability**

**Priority:** High

The system shall support daily recurring booking checks against instructor availability.

### **32 - Weekly Recurring Availability**

**Priority:** Critical

The system shall support weekly recurring booking checks against instructor availability.

### **33 - Unavailable Occurrence Handling**

**Priority:** Critical

The system shall clearly show unavailable occurrences before confirming a recurring booking.

# **10.31 Business Rules**

- Only approved and active instructors may publish bookable availability.
- Students shall only see slots that are valid at the time of display.
- Confirmed bookings take priority over future availability changes.
- Demo lessons and paid lessons both consume instructor availability.
- A slot cannot be booked if it overlaps with another confirmed or reserved slot.
- Instructor vacation mode shall prevent new bookings during the vacation period.
- Availability changes must be recorded in activity history.
- All confirmed booking times must be stored in UTC.
- Students shall view times in their selected local timezone.
- Instructors shall define availability in their own local timezone.
- Recurring bookings shall not be confirmed unless all required occurrences are valid or explicitly handled according to platform policy.
- Waitlist notifications shall not guarantee booking; they only notify students of availability.

# **10.32 Validation Rules**

- Availability start time must be earlier than end time.
- Availability windows cannot overlap on the same day for the same instructor unless explicitly merged by the system.
- Break periods must fall within or relate clearly to defined availability windows.
- Blocked periods cannot be created in the past.
- Vacation end date must be after vacation start date.
- Lesson duration must fit within the available slot after applying buffer time.
- Recurring schedules must contain valid future occurrences.
- Availability updates cannot invalidate confirmed bookings without an explicit reschedule or cancellation workflow.

# **10.33 User Workflows**

## **1 - Instructor Creates Weekly Availability**

- Instructor opens availability settings.
- Instructor selects days of the week.
- Instructor defines start and end times.
- Instructor selects supported lesson types.
- Instructor optionally adds breaks.
- Instructor saves schedule.
- System validates conflicts.
- System publishes bookable availability.

## **2 - Student Views Available Slots**

- Student opens instructor profile.
- Student selects subject.
- Student selects lesson type.
- Student selects lesson duration.
- System calculates valid slots.
- Student views available dates and times in local timezone.
- Student selects preferred slot.

## **3 - Instructor Activates Vacation Mode**

- Instructor opens vacation settings.
- Instructor selects start and end dates.
- Instructor optionally enters a private reason.
- System checks affected bookings.
- Instructor confirms.
- System blocks new bookings for the vacation period.
- System updates marketplace availability.

## **4 - Waitlist Trigger**

- Student sees no suitable slots.
- Student joins waitlist with preferred time.
- Instructor later opens matching availability.
- System identifies eligible waitlisted students.
- System sends notification.
- Student books on first-come, first-served basis.

# **10.34 Exception Handling**

## **No Slots Available**

The system shall show:

- Fully booked message
- Waitlist option
- Similar instructors
- Alternative available days

## **Slot Becomes Unavailable During Checkout**

The system shall notify the student and request selection of a new slot.

## **Payment Fails After Slot Reservation**

The system shall release the reserved slot after the configured reservation expiry.

## **Instructor Changes Availability with Existing Bookings**

The system shall preserve existing bookings and warn the instructor about affected dates.

## **Timezone Conversion Issue**

The system shall display both original and converted times where clarity is required, especially in notifications and calendar invites.

# **10.35 Notifications**

The Availability Engine may trigger notifications for:

### **Instructor**

- Availability created
- Availability updated
- Booking created
- Booking conflict warning
- Vacation mode activated
- Waitlist demand summary

### **Student**

- Waitlist slot available
- Selected slot reserved
- Slot expired
- Booking confirmed
- Schedule changed

### **Administrator**

- Instructor availability issue
- High waitlist demand
- Repeated instructor schedule disruption

# **10.36 Reports & Analytics**

Availability reports may include:

- Instructor available hours
- Instructor booked hours
- Instructor utilization
- Fully booked instructors
- Low-availability instructors
- Waitlist demand
- Subject-wise demand
- Country-wise demand
- Demo slot availability
- Paid slot availability
- Recurring schedule demand

# **10.37 Administrative Configuration**

Administrators shall be able to configure:

- Supported lesson durations
- Default buffer time
- Minimum booking notice
- Maximum advance booking window
- Reservation expiry time
- Waitlist behavior
- Vacation mode rules
- Scheduling feature flags
- Country-specific scheduling settings
- Instructor override permissions

# **10.38 Acceptance Criteria**

- Instructors can create and manage recurring weekly availability without administrative assistance.
- Students see only valid, bookable, timezone-correct slots.
- The system prevents double-booking across demo, paid, and recurring lessons.
- Recurring lesson schedules are validated before confirmation.
- Vacation mode prevents new bookings while preserving instructor ranking history.
- Waitlist notifications are triggered when matching availability becomes available.
- Availability changes are recorded in activity history and visible to authorized users.

# **10.39 Future Enhancements**

The Availability & Scheduling Engine is designed to support:

- AI-powered schedule recommendations.
- Smart instructor workload balancing.
- Calendar sync with Google Calendar.
- Calendar sync with Outlook.
- Country-specific public holiday automation.
- Student preferred schedule matching.
- Demand-based availability suggestions.
- Auto-suggested recurring schedules.
- Advanced waitlist prioritization.
- Group class scheduling.
- Instructor capacity forecasting.
- Scheduling risk alerts.

# **10.40 Chapter Summary**

The Availability & Scheduling Engine is a core operational system within STEM Learning. It ensures that instructor availability is accurate, student booking options are valid, recurring schedules are reliable, and global timezone complexity is handled consistently.

This module provides the scheduling foundation required for Demo Booking, Paid Booking, Recurring Lessons, Waitlists, Meeting Management, Notifications, and Analytics.

A strong availability engine reduces administrative workload, prevents conflicts, improves student confidence, and enables the platform to scale globally.

# **CHAPTER 11 - BOOKING & RESERVATION ENGINE**

# **11.1 Introduction**

The Booking & Reservation Engine manages the complete lifecycle of lesson reservations within the STEM Learning platform.

This module is responsible for converting student intent into confirmed learning sessions by coordinating instructor availability, pricing, wallet balance, payment processing, meeting creation, notifications, cancellation rules, rescheduling rules, recurring schedules, attendance status, no-show handling, completion, reviews, homework, and settlement triggers.

The Booking & Reservation Engine is one of the most critical business domains of the platform because it directly impacts revenue, student experience, instructor utilization, operational reliability, and financial accuracy.

This module must prevent double bookings, protect students during payment, reserve selected slots during checkout, enforce platform policies, and maintain a complete history for every booking.

# **11.2 Purpose**

The purpose of this module is to provide a reliable and automated booking system that supports:

- Free demo lesson booking
- Paid lesson booking
- Non-recurring lesson booking
- Daily recurring lesson booking
- Weekly recurring lesson booking
- Slot reservation during checkout
- Wallet-based payments
- Payment gateway-based payments
- Cancellation
- Rescheduling
- No-show handling
- Lesson completion
- Booking history
- Booking lifecycle tracking

The module must ensure that every confirmed booking is valid, paid where required, conflict-free, timezone-safe, auditable, and ready for meeting creation.

# **11.3 Business Objectives**

The Booking & Reservation Engine shall support the following business objectives:

- Allow students to book lessons with minimum friction.
- Support free instructor-specific demo lessons.
- Support paid one-to-one lessons.
- Support recurring learning schedules.
- Minimize administrative intervention.
- Prevent double booking and scheduling conflicts.
- Support wallet-based recurring payments.
- Maintain complete booking history.
- Enforce cancellation and rescheduling policies.
- Trigger downstream workflows such as meeting creation, notifications, homework, reviews, and instructor settlement.

# **11.4 Scope**

This chapter covers:

## **Booking Types**

- Free demo lesson
- Paid single lesson
- Paid recurring lesson
- Daily recurring lesson
- Weekly recurring lesson

## **Reservation**

- Temporary slot reservation
- Reservation expiry
- Checkout protection
- Reservation release

## **Payment Dependency**

- Wallet balance check
- Gateway payment
- Failed payment behavior
- Booking confirmation after successful payment

## **Booking Lifecycle**

- Draft
- Reserved
- Pending Payment
- Confirmed
- Upcoming
- In Progress
- Completed
- Cancelled
- Rescheduled
- No-Show
- Missed
- Failed
- Expired

## **Operations**

- Cancellation
- Wallet refund
- Rescheduling
- Attendance tracking
- Completion
- Review eligibility
- Settlement trigger

# **11.5 Booking Philosophy**

The platform shall treat every booking as a controlled business transaction, not simply a calendar event.

A booking represents:

- A student learning commitment
- An instructor teaching commitment
- A financial transaction
- A scheduled online meeting
- A future educational record
- A settlement-related event
- A reporting and analytics data point

Therefore, every booking must be traceable, auditable, and governed by platform policy.

# **11.6 Booking Types**

## **Free Demo Lesson**

A free demo lesson allows a student to evaluate an instructor before booking paid learning.

Rules:

- Demo is free.
- Student selects instructor.
- One free demo per student per instructor.
- Demo occupies instructor availability.
- Demo creates a meeting link.
- Demo contributes to instructor analytics.
- Demo may lead to paid lessons or recurring learning.

## **Paid Single Lesson**

A paid single lesson is a one-time confirmed session between one student and one instructor.

Rules:

- Requires wallet balance or successful payment.
- Uses platform-controlled pricing.
- Creates meeting link after confirmation.
- Supports cancellation and rescheduling according to policy.
- Can be connected to a Learning Plan.

## **Paid Recurring Lesson**

A recurring lesson schedule creates multiple future lesson occurrences based on a repeated pattern.

Supported Version 1 recurrence types:

- Daily
- Weekly

Rules:

- Student chooses instructor.
- Student selects subject, duration, schedule, and recurrence pattern.
- Every occurrence must be validated against availability.
- Wallet-based auto-deduction is the recommended Version 1 model.
- Student must maintain sufficient wallet balance before upcoming lessons.
- Unavailable occurrences must be clearly shown before confirmation.

# **11.7 Booking Lifecycle**

Every booking progresses through defined lifecycle states.

Selected Slot

│

▼

Reserved

│

▼

Pending Payment

│

▼

Confirmed

│

▼

Upcoming

│

▼

In Progress

│

▼

Completed

│

▼

Review Eligible

Alternative lifecycle outcomes include:

Reserved

│

▼

Expired

Confirmed

│

▼

Cancelled

Confirmed

│

▼

Rescheduled

Upcoming

│

▼

Student No-Show

Upcoming

│

▼

Instructor No-Show

Upcoming

│

▼

Technical Issue

The system shall maintain a complete lifecycle history for every booking.

# **11.8 Booking Status Definitions**

## **Draft**

The student has started the booking journey but has not selected a final slot or completed required information.

## **Reserved**

The selected slot is temporarily held while checkout or confirmation is in progress.

## **Pending Payment**

The booking requires payment confirmation before becoming confirmed.

## **Confirmed**

The booking has passed all validation checks and payment requirements.

## **Upcoming**

The confirmed lesson is scheduled for a future time.

## **In Progress**

The scheduled lesson time has started and the meeting is active or expected to be active.

## **Completed**

The lesson has ended and has been finalized by instructor confirmation or automatic completion.

## **Cancelled**

The booking has been cancelled according to platform policy.

## **Rescheduled**

The original booking has been moved to another valid time slot.

## **Student No-Show**

The student did not join within the configured grace period.

## **Instructor No-Show**

The instructor did not join within the configured grace period.

## **Missed**

Neither student nor instructor attended, or the lesson could not proceed according to policy.

## **Expired**

The reserved slot expired before booking confirmation.

## **Failed**

The booking could not be completed due to payment failure, invalid slot, system error, or policy conflict.

# **11.9 Booking Flow - Free Demo Lesson**

The free demo lesson flow is:

Student views instructor profile

│

▼

Selects Free Demo

│

▼

Selects subject and duration

│

▼

Selects available slot

│

▼

System validates demo eligibility

│

▼

System reserves slot

│

▼

System confirms booking

│

▼

System creates meeting

│

▼

Notifications sent

No payment is required for demo lessons.

# **11.10 Booking Flow - Paid Single Lesson**

The paid lesson flow is:

Student selects instructor

│

▼

Selects subject

│

▼

Selects education level

│

▼

Selects lesson duration

│

▼

Selects available slot

│

▼

System calculates localized price

│

▼

System reserves slot

│

▼

Student pays using wallet or payment gateway

│

▼

System confirms payment

│

▼

Booking confirmed

│

▼

Meeting created

│

▼

Notifications sent

# **11.11 Booking Flow - Recurring Lesson**

The recurring booking flow is:

Student selects instructor

│

▼

Selects subject and education level

│

▼

Selects lesson duration

│

▼

Selects recurrence type

│

▼

Selects daily or weekly schedule

│

▼

System checks all future occurrences

│

▼

Unavailable occurrences shown

│

▼

Student confirms valid schedule

│

▼

System creates recurring booking plan

│

▼

System creates lesson occurrences

│

▼

Wallet deduction rules applied

│

▼

Notifications sent

The recommended Version 1 model is wallet-based recurring payment rather than subscription billing.

# **11.12 Slot Reservation During Checkout**

When a student selects a bookable slot, the system shall temporarily reserve the slot.

Reservation purpose:

- Prevent duplicate booking
- Protect checkout
- Prevent payment conflict
- Improve booking reliability

Reservation rules:

- Reservation expiry time is configurable.
- Reserved slots are not visible as bookable to other students.
- Expired reservations are automatically released.
- Confirmed bookings permanently block the slot.
- Failed payments release the slot according to configured retry behavior.

# **11.13 Demo Eligibility Rules**

The system shall validate demo eligibility before confirmation.

Demo eligibility rules:

- Student must be registered.
- Student email must be verified.
- Instructor must be active and approved.
- Instructor must support demo lessons.
- Student must not have already used a free demo with the same instructor.
- Selected slot must be available.
- Demo duration must match platform configuration.
- Subject must be valid and active.

If eligibility fails, the system shall display a clear reason.

# **11.14 Paid Booking Eligibility Rules**

The system shall validate paid booking eligibility before payment.

Eligibility rules:

- Student must be registered and verified.
- Instructor must be approved and active.
- Subject must be active.
- Education level must be active.
- Lesson duration must be supported.
- Pricing must exist for student country and currency.
- Slot must be available.
- Booking must comply with minimum notice.
- Booking must comply with advance booking window.

# **11.15 Pricing Resolution**

For paid lessons, the system shall resolve student-facing price based on:

- Student billing country
- Student billing currency
- Subject
- Education level
- Lesson duration
- Applicable pricing configuration
- Promotional rules, where enabled

Instructor compensation shall not be displayed to students.

Instructor compensation shall not determine the student-facing price directly.

# **11.16 Payment Model**

Version 1 supports:

- Wallet payment
- Payment gateway payment
- Mixed payment support may be considered later

Recommended recurring model:

- Student recharges wallet.
- Recurring lessons are scheduled.
- Wallet is debited according to configured timing.
- Low wallet balance triggers reminders.
- If wallet balance is insufficient, upcoming lessons follow configured failure policy.

# **11.17 Wallet-Based Booking**

When a student pays using wallet:

- System checks wallet balance.
- System debits required amount.
- Wallet transaction is created.
- Booking is confirmed.
- Invoice or receipt is generated where applicable.
- Meeting creation is triggered.

Wallet balance shall not become negative.

# **11.18 Payment Gateway Booking**

When a student pays using a payment gateway:

- System creates payment request.
- Slot remains reserved during payment.
- Gateway confirms success or failure.
- Successful payment confirms booking.
- Failed payment releases or maintains reservation according to retry policy.
- Payment event is permanently recorded.

# **11.19 Recurring Wallet Deduction**

For recurring lessons, the platform shall use wallet-based deduction.

Possible deduction models:

## **Model A - Deduct Before Each Lesson**

Wallet is debited a configured time before each lesson.

Recommended for Version 1.

## **Model B - Deduct Immediately for All Scheduled Lessons**

Wallet is debited for all confirmed future lessons upfront.

Not recommended for Version 1 unless package sales are introduced.

## **Model C - Deduct Monthly**

Future enhancement aligned with subscriptions.

Version 1 recommendation:

- Deduct before each lesson.
- Send low-balance reminders.
- Hold or pause future lessons if wallet is insufficient.

# **11.20 Low Wallet Balance Handling**

If a student has insufficient wallet balance for an upcoming recurring lesson:

The system may:

- Notify student.
- Allow wallet recharge.
- Temporarily mark occurrence as payment pending.
- Cancel occurrence after grace period.
- Notify instructor if lesson is not confirmed.

The exact timing is configurable.

# **11.21 Booking Confirmation**

A booking is confirmed only when:

- Slot is valid.
- Instructor is eligible.
- Student is eligible.
- Demo eligibility or payment requirement is satisfied.
- No conflict exists.
- Required system records are created.

Upon confirmation, the system shall:

- Block instructor availability.
- Create booking record.
- Trigger meeting creation.
- Send notifications.
- Update dashboards.
- Record activity timeline.

# **11.22 Meeting Creation Trigger**

Meeting creation occurs after booking confirmation.

For demo lessons:

- Meeting is created after demo booking confirmation.

For paid lessons:

- Meeting is created after payment confirmation.

Meeting links shall be visible only to authorized participants.

# **11.23 Calendar Invite**

The system should generate calendar-compatible lesson information.

Calendar invite should include:

- Lesson title
- Date and time
- Student local time
- Instructor local time
- Meeting link
- Subject
- Duration

Calendar integration may be enhanced in later versions.

# **11.24 Cancellation Policy**

Students may cancel bookings according to platform configuration.

Rules:

- Student cancellation is allowed.
- Eligible refund is credited to wallet only.
- Refund amount depends on cancellation window and policy.
- Cancelled lessons release instructor availability where applicable.
- Cancellation history is recorded.

Instructor-initiated cancellation may be restricted or subject to performance impact.

# **11.25 Refund to Wallet**

Eligible refunds shall be credited to the student's wallet.

Rules:

- Refunds are issued in the original billing currency.
- Refunds create wallet credit transactions.
- Refunds do not delete original payment records.
- Refunds are traceable to the cancelled booking.
- Admin override is permitted only with audit reason.

# **11.26 Rescheduling Policy**

Students may reschedule lessons according to platform rules.

Rescheduling rules:

- Maximum allowed reschedules are configurable.
- Rescheduling must respect instructor availability.
- Rescheduling cannot bypass payment rules.
- Rescheduled booking retains history.
- Notifications must be sent to both parties.
- Meeting information must be updated.

Recurring bookings may allow:

- Reschedule a single occurrence
- Reschedule future occurrences
- Reschedule entire recurring plan

Version 1 should support single occurrence rescheduling and may support full recurring plan changes if scope allows.

# **11.27 Instructor Rescheduling**

Instructor-initiated rescheduling should be controlled.

Rules:

- Instructor may request reschedule where allowed.
- Student confirmation may be required.
- Frequent instructor rescheduling affects analytics.
- Admin may monitor repeated instructor disruptions.

# **11.28 No-Show Handling**

The Booking Engine shall support no-show outcomes.

## **Student No-Show**

If the student does not join within grace period:

- Booking may be marked Student No-Show.
- Refund is not granted by default unless policy allows.
- Instructor compensation may be processed according to policy.
- Student receives notification.

## **Instructor No-Show**

If the instructor does not join within grace period:

- Student receives wallet refund.
- Instructor performance is affected.
- Admin is notified.
- Repeated no-shows may affect instructor status.

## **Both Absent**

If both are absent:

- Booking is marked Missed.
- Policy determines refund, reschedule, or forfeit.

## **Technical Issue**

If a technical issue is reported:

- Booking may be marked Technical Issue.
- Evidence may be collected.
- Admin review may be triggered if needed.
- Resolution may include wallet refund or reschedule.

# **11.29 Lesson Completion**

The system shall use a hybrid completion model.

Preferred flow:

- Lesson ends.
- Instructor marks lesson as completed.
- Instructor may add lesson summary or notes where enabled.
- System finalizes lesson.
- Review eligibility opens.
- Homework may be assigned.
- Settlement eligibility begins.

Fallback flow:

- Lesson scheduled end time passes.
- Instructor does not mark completion.
- System waits configured auto-completion delay.
- System auto-completes lesson if no issue is detected.
- Activity is recorded.

# **11.30 Completion Dependencies**

Completion may depend on:

- Scheduled end time
- Meeting attendance
- Instructor confirmation
- No reported technical issue
- No admin hold
- Payment success

Completed lessons cannot be deleted.

# **11.31 Review Eligibility**

Students may submit reviews only after eligible lesson completion.

Rules:

- Demo reviews may be allowed or disabled by configuration.
- Paid lesson reviews are linked to completed lessons.
- No-show lessons may not be eligible for standard review unless configured.
- Reviews follow moderation policy.

# **11.32 Homework Trigger**

After lesson completion, instructors may assign homework.

Homework should reference:

- Booking
- Lesson
- Student
- Instructor
- Subject
- Learning Plan, where applicable

# **11.33 Instructor Settlement Trigger**

A completed paid lesson may trigger instructor earning eligibility.

Settlement rules are governed by the Instructor Settlement chapter.

Booking Engine responsibilities:

- Mark lesson completion.
- Notify financial domain.
- Provide booking amount and lesson metadata.
- Preserve booking history.

# **11.34 Booking Timeline**

Every booking shall maintain an activity timeline.

Timeline events may include:

- Slot selected
- Slot reserved
- Payment initiated
- Payment succeeded
- Booking confirmed
- Meeting created
- Reminder sent
- Lesson started
- Student joined
- Instructor joined
- Lesson completed
- Review submitted
- Homework assigned
- Booking cancelled
- Booking rescheduled
- Refund credited
- No-show recorded

# **11.35 Student Booking Dashboard**

Students should view:

- Upcoming lessons
- Demo lessons
- Paid lessons
- Recurring lessons
- Cancelled lessons
- Completed lessons
- Homework related to lessons
- Meeting links
- Reschedule actions
- Cancellation actions
- Review actions

# **11.36 Instructor Booking Dashboard**

Instructors should view:

- Today's lessons
- Upcoming demo lessons
- Upcoming paid lessons
- Recurring students
- Lesson completion actions
- No-show reporting
- Homework actions
- Student learning plan context
- Meeting links

# **11.37 Admin Booking Management**

Administrators should be able to:

- View all bookings
- Filter by status
- Filter by country
- Filter by instructor
- Filter by student
- Filter by subject
- View booking timeline
- Review cancellations
- Review no-shows
- Apply permitted overrides
- Export booking reports

Admin changes must be audit logged.

# **11.38 Functional Requirements**

## **Booking Creation**

### **Demo Booking Creation**

**Priority:** Critical

The system shall allow eligible students to book a free demo lesson with an active approved instructor.

### **Paid Booking Creation**

**Priority:** Critical

The system shall allow eligible students to book a paid single lesson with an active approved instructor.

### **Recurring Booking Creation**

**Priority:** Critical

The system shall allow students to create daily or weekly recurring lesson schedules with an eligible instructor.

### **Booking Reference Number**

**Priority:** High

The system shall generate a unique booking reference number for every booking.

### **Learning Plan Association**

**Priority:** High

The system shall allow bookings to be associated with a Learning Plan where applicable.

## **Reservation**

### **Slot Reservation**

**Priority:** Critical

The system shall temporarily reserve a selected slot during checkout.

### **Reservation Expiry**

**Priority:** Critical

The system shall automatically release expired slot reservations.

### **Reservation Conflict Prevention**

**Priority:** Critical

The system shall prevent multiple active reservations for the same instructor slot.

## **Eligibility**

### **Demo Eligibility Validation**

**Priority:** Critical

The system shall validate free demo eligibility before confirming a demo booking.

### **Paid Lesson Eligibility Validation**

**Priority:** Critical

The system shall validate paid lesson eligibility before payment processing.

### **Recurring Schedule Validation**

**Priority:** Critical

The system shall validate every occurrence of a recurring schedule before confirmation.

## **Pricing & Payment**

### **Price Resolution**

**Priority:** Critical

The system shall resolve the applicable student-facing price based on student country, currency, subject, education level, and lesson duration.

### **Wallet Payment**

**Priority:** Critical

The system shall allow students to pay for eligible bookings using wallet balance.

### **Payment Gateway Payment**

**Priority:** Critical

The system shall allow students to pay for eligible bookings using the configured payment gateway for their country.

### **Failed Payment Handling**

**Priority:** Critical

The system shall prevent booking confirmation when payment fails.

### **Low Wallet Balance Handling**

**Priority:** High

The system shall notify students when wallet balance is insufficient for upcoming recurring lessons.

## **Confirmation**

### **Booking Confirmation**

**Priority:** Critical

The system shall confirm bookings only after all eligibility, availability, and payment requirements are satisfied.

### **Meeting Creation Trigger**

**Priority:** Critical

The system shall trigger meeting creation after booking confirmation.

### **Booking Notifications**

**Priority:** Critical

The system shall notify student and instructor after booking confirmation.

## **Cancellation**

### **Student Cancellation**

**Priority:** Critical

The system shall allow students to cancel bookings according to platform policy.

### **Wallet Refund**

**Priority:** Critical

The system shall credit eligible cancellation refunds to the student's wallet.

### **Cancellation Timeline**

**Priority:** High

The system shall record cancellation events in the booking timeline.

## **Rescheduling**

### **Student Rescheduling**

**Priority:** Critical

The system shall allow students to reschedule eligible bookings according to platform policy.

### **Reschedule Availability Check**

**Priority:** Critical

The system shall require valid instructor availability before confirming a reschedule.

### **Reschedule Notifications**

**Priority:** High

The system shall notify affected participants after successful rescheduling.

### **Recurring Occurrence Reschedule**

**Priority:** High

The system shall allow individual recurring lesson occurrences to be rescheduled where permitted.

## **Attendance & No-Show**

### **Attendance Tracking**

**Priority:** High

The system shall track student and instructor attendance where meeting provider data is available.

### **Student No-Show**

**Priority:** High

The system shall support marking a lesson as Student No-Show.

### **Instructor No-Show**

**Priority:** High

The system shall support marking a lesson as Instructor No-Show.

### **Technical Issue Reporting**

**Priority:** Medium

The system shall allow participants to report technical issues within a configured period.

## **Completion**

### **Instructor Completion**

**Priority:** Critical

The system shall allow instructors to mark eligible lessons as completed.

### **Auto Completion**

**Priority:** High

The system shall automatically complete eligible lessons after a configured delay if no action or issue is pending.

### **Completion Timeline**

**Priority:** High

The system shall record lesson completion in the booking timeline.

### **Review Eligibility Trigger**

**Priority:** High

The system shall make eligible completed lessons available for review.

### **Homework Trigger**

**Priority:** High

The system shall allow homework assignment after lesson completion.

## **Admin**

### **Admin Booking View**

**Priority:** Critical

Administrators shall be able to view and filter bookings across the platform.

### **Admin Override**

**Priority:** High

Administrators may override booking outcomes only according to permissions and audit policy.

### **Booking Export**

**Priority:** Medium

Administrators shall be able to export booking records for reporting where permitted.

# **11.39 Business Rules**

- Only verified students may book lessons.
- Only approved and active instructors may receive bookings.
- A student may book one free demo per instructor.
- Demo lessons are always free in Version 1.
- Paid lessons require successful wallet deduction or payment confirmation.
- A booking cannot be confirmed without a valid available slot.
- A booking cannot overlap with another confirmed or reserved booking for the same instructor.
- All confirmed booking times must be stored in UTC.
- Students view booking times in their configured local timezone.
- Instructors view booking times in their configured local timezone.
- Student-facing price is controlled by the platform.
- Instructor compensation is never displayed to students.
- Eligible refunds are credited to wallet only in Version 1.
- Completed lessons cannot be deleted.
- Every booking must maintain a lifecycle history.
- Recurring bookings must validate all planned occurrences before confirmation.
- Meeting links are generated only after booking confirmation.
- Cancellation and rescheduling rules are configurable.

# **11.40 Validation Rules**

- Selected instructor must be approved and active.
- Selected subject must be active and available in the student's country.
- Selected lesson duration must be supported by platform configuration.
- Selected slot must be available at the time of reservation.
- Selected slot must satisfy minimum booking notice.
- Selected slot must fall within maximum advance booking window.
- Student must have sufficient wallet balance for wallet payment.
- Payment must be confirmed before paid booking confirmation.
- Recurring schedules must contain at least one valid future occurrence.
- Cancellation requests must satisfy configured cancellation rules.
- Reschedule requests must satisfy configured rescheduling rules.
- A completed booking cannot be rescheduled.
- A cancelled booking cannot be completed.

# **11.41 User Workflows**

## **Free Demo Booking**

- Student opens instructor profile.
- Student clicks Book Free Demo.
- Student selects subject.
- Student selects available demo slot.
- System validates demo eligibility.
- System reserves slot.
- System confirms demo booking.
- System creates meeting.
- System sends notifications.

## **Paid Single Lesson Booking**

- Student selects instructor.
- Student selects subject and education level.
- Student selects lesson duration.
- Student selects available slot.
- System calculates price.
- System reserves slot.
- Student selects wallet or payment gateway.
- System confirms payment.
- System confirms booking.
- System creates meeting.
- System sends notifications.

## **Recurring Lesson Booking**

- Student selects instructor.
- Student selects subject, duration, and learning goal.
- Student selects recurrence pattern.
- Student selects preferred days and times.
- System validates all occurrences.
- System displays unavailable occurrences if any.
- Student resolves conflicts.
- Student confirms schedule.
- System creates recurring booking plan.
- System creates upcoming occurrences.
- Wallet deduction rules are applied.
- Notifications are sent.

## **Student Cancels Booking**

- Student opens booking details.
- Student clicks cancel.
- System checks cancellation eligibility.
- System displays refund outcome.
- Student confirms cancellation.
- System cancels booking.
- System credits wallet refund if eligible.
- System releases instructor slot.
- Notifications are sent.

## **Student Reschedules Booking**

- Student opens booking details.
- Student clicks reschedule.
- System checks reschedule eligibility.
- Student selects new available slot.
- System reserves new slot.
- Student confirms change.
- System updates booking.
- Meeting details are updated.
- Notifications are sent.

## **Lesson Completion**

- Lesson reaches scheduled end time.
- Instructor opens lesson record.
- Instructor marks lesson completed.
- System records completion.
- Review eligibility opens.
- Homework option becomes available.
- Settlement eligibility is triggered.

# **11.42 Exception Handling**

## **Slot Already Taken**

If a selected slot is no longer available, the system shall notify the student and request selection of another slot.

## **Reservation Expired**

If the reservation expires during checkout, the system shall release the slot and require the student to restart slot selection.

## **Payment Failed**

If payment fails, the booking shall not be confirmed. The student may retry payment while reservation is active.

## **Wallet Insufficient**

If wallet balance is insufficient, the system shall prompt the student to recharge or select another payment method.

## **Recurring Conflict**

If a recurring schedule contains unavailable occurrences, the system shall show conflict dates and require resolution before confirmation.

## **Meeting Creation Failed**

If meeting creation fails after booking confirmation, the system shall retry automatically and notify administrators if the issue persists.

## **Cancellation Outside Policy**

If cancellation is outside the allowed policy window, the system shall show the applicable outcome clearly.

## **Instructor Unavailable After Confirmation**

If instructor becomes unavailable after confirmation, the system shall follow cancellation or rescheduling policy and notify affected users.

# **11.43 Notifications**

Booking-related notifications include:

## **Student Notifications**

- Booking confirmed
- Payment successful
- Payment failed
- Demo booked
- Lesson reminder
- Recurring lesson reminder
- Booking cancelled
- Wallet refund credited
- Booking rescheduled
- Meeting link available
- Lesson completed
- Review request
- Homework assigned
- Low wallet balance

## **Instructor Notifications**

- New demo booking
- New paid booking
- Recurring student booking
- Booking cancelled
- Booking rescheduled
- Lesson reminder
- Student no-show
- Completion pending
- Homework pending

## **Admin Notifications**

- Booking conflict issue
- Meeting creation failure
- Instructor no-show
- Technical issue report
- Repeated cancellation pattern
- High refund activity

Channels depend on platform configuration:

- Email
- SMS
- WhatsApp
- In-app notification

# **11.44 Reports & Analytics**

Booking reports may include:

- Total bookings
- Demo bookings
- Paid bookings
- Recurring bookings
- Cancelled bookings
- Rescheduled bookings
- Completed lessons
- No-show rate
- Demo-to-paid conversion
- Revenue by booking type
- Country-wise bookings
- Subject-wise bookings
- Instructor utilization
- Student repeat booking rate
- Wallet-funded bookings
- Payment gateway-funded bookings

# **11.45 Administrative Configuration**

Administrators shall be able to configure:

- Demo duration
- Demo eligibility rules
- Booking notice period
- Advance booking window
- Reservation expiry time
- Cancellation windows
- Refund rules
- Reschedule limits
- Auto-completion delay
- No-show grace period
- Technical issue reporting window
- Recurring booking limits
- Low wallet balance reminder timing
- Booking notification templates
- Country-specific booking policies

# **11.46 Acceptance Criteria**

- Students can book a free demo with an eligible instructor without payment.
- Students can book paid lessons only after successful wallet deduction or payment confirmation.
- The system prevents double booking across demo, paid, and recurring lessons.
- Recurring bookings clearly show unavailable occurrences before confirmation.
- Students can cancel eligible bookings and receive wallet refunds according to policy.
- Students can reschedule eligible bookings using valid instructor availability.
- Completed lessons become eligible for review, homework, and settlement workflows.
- Every booking maintains a complete lifecycle timeline.
- Administrators can monitor and manage bookings without directly interfering unless required by policy.

# **11.47 Future Enhancements**

The Booking & Reservation Engine is designed to support:

- Lesson packages
- Subscription billing
- Group classes
- Parent-managed bookings
- Corporate bookings
- AI schedule recommendations
- AI tutor matching during booking
- Smart recurring schedule suggestions
- Dynamic pricing
- Booking risk scoring
- Auto-reschedule suggestions
- Smart no-show prediction
- Integrated attendance analytics
- Mobile app booking flow

# **11.48 Chapter Summary**

The Booking & Reservation Engine is the commercial and operational core of STEM Learning. It converts student intent into confirmed lessons while enforcing availability, pricing, payment, cancellation, rescheduling, and completion policies.

This module ensures that every lesson is valid, auditable, timezone-safe, financially traceable, and connected to the broader learning journey.

A strong Booking & Reservation Engine enables the platform to scale globally while maintaining student trust, instructor reliability, and operational control.

#

#

# **CHAPTER 12 - VIRTUAL CLASSROOM & MEETING MANAGEMENT**

# **12.1 Introduction**

The Virtual Classroom & Meeting Management module governs how online lessons are delivered between students and instructors through platform-controlled meeting infrastructure.

Since STEM Learning is an online-only learning platform, the meeting experience is a core part of the product. Every confirmed demo lesson and paid lesson must have a secure, reliable, and controlled virtual classroom experience.

This module defines how meetings are created, accessed, secured, recorded, monitored, completed, and connected with booking, attendance, notifications, learning records, homework, analytics, and future AI services.

The meeting system must protect the platform from off-platform leakage by ensuring that instructors and students do not directly create or exchange external meeting links outside STEM Learning.

# **12.2 Purpose**

The purpose of this module is to provide a controlled online classroom experience for all lessons.

The module must ensure that:

- Meetings are created only after booking confirmation.
- Meetings are owned by the platform.
- Meeting links are visible only to authorized participants.
- Students and instructors do not need to exchange personal contact information.
- Admins can observe live sessions where permitted.
- Attendance can be tracked.
- Recordings can be stored under platform ownership.
- Meeting history remains connected to booking and learning records.
- Future AI capabilities can use meeting recordings and transcripts.

# **12.3 Business Objectives**

The Virtual Classroom & Meeting Management module shall support the following business objectives:

- Deliver reliable online lessons.
- Protect platform-controlled student-instructor relationships.
- Reduce off-platform communication risk.
- Automate meeting creation.
- Improve attendance tracking.
- Support lesson completion workflows.
- Enable quality assurance through admin observation.
- Support meeting recordings.
- Prepare the platform for future AI lesson summaries.
- Maintain secure access to meeting links and recordings.

# **12.4 Scope**

This chapter covers:

## **Meeting Creation**

- Demo lesson meetings
- Paid lesson meetings
- Recurring lesson meetings
- Platform-owned meeting creation
- Meeting provider integration

## **Meeting Access**

- Student access
- Instructor access
- Admin observer access
- Meeting link visibility
- Expiring access links

## **Meeting Security**

- Restricted access
- No direct email exposure where possible
- Platform-generated links
- Meeting access control
- Anti-leakage rules

## **Meeting Recording**

- Recording enablement
- Recording ownership
- Initial Google Drive storage
- Future S3 migration
- Recording access permissions

## **Attendance**

- Student attendance
- Instructor attendance
- Join time
- Leave time
- Duration attended
- No-show support

## **Quality Assurance**

- Admin observer mode
- Live class monitoring
- Recording review
- Platform governance

# **12.5 Meeting Strategy**

STEM Learning shall use a platform-controlled meeting strategy.

The platform, not the instructor, shall create and own all lesson meetings.

The instructor shall not manually create their own meeting link for lessons unless explicitly allowed by an administrator in exceptional circumstances.

The recommended meeting ownership model is:

Student books lesson

│

▼

Booking confirmed

│

▼

Platform creates meeting

│

▼

Meeting owned by platform account

│

▼

Student and instructor receive controlled access

│

▼

Meeting history recorded

# **12.6 Meeting Provider Strategy**

The platform may support multiple meeting providers over time.

## **Version 1 Recommended Provider Strategy**

For Version 1, the platform may initially use:

- Google Meet through Google Workspace
- Platform-owned Google Workspace account
- Platform-managed meeting creation
- Platform-managed recording storage in Google Drive where available

The recommended ownership account may be:

- <classes@stemlearning.com>
- <meeting@stemlearning.com>
- <classroom@stemlearning.com>

The actual account name is configurable.

## **Future Provider Strategy**

Future versions may support:

- Zoom
- 100ms
- Daily.co
- LiveKit
- Custom WebRTC classroom

The platform should remain provider-agnostic so that the meeting provider can be changed later without redesigning the business workflow.

# **12.7 Zoom Compatibility**

Zoom can support many requirements needed by STEM Learning, such as:

- API-based meeting creation
- Scheduled meetings
- Recurring meetings
- Waiting rooms
- Host controls
- Recording
- Webhooks
- Attendance reports
- Meeting analytics

However, Zoom may still expose participant names and may not fully prevent students and instructors from communicating outside the platform during or after a class.

Therefore, Zoom is suitable as a meeting provider, but it does not fully solve business leakage risk by itself.

For maximum control in future versions, a programmable video platform such as 100ms, Daily.co, or LiveKit may provide stronger control over the classroom experience.

# **12.8 Provider-Agnostic Design Principle**

The system shall not make the business workflow dependent on a single meeting provider.

The platform should define a generic meeting interface conceptually supporting:

- Create meeting
- Update meeting
- Cancel meeting
- Generate join link
- Fetch attendance
- Fetch recording
- Handle webhook
- Store meeting metadata

This allows the platform to start with Google Workspace and later migrate to Zoom, S3-backed recordings, or a custom classroom provider.

# **12.9 Meeting Ownership Policy**

All lesson meetings shall be owned by the platform.

Rules:

- Instructor-created personal links are not allowed for standard bookings.
- Student-created links are not allowed.
- Meeting ownership belongs to the platform account.
- Meeting links are generated only after booking confirmation.
- Meeting links are associated with a booking reference.
- Meeting metadata is stored for future audit and analytics.

# **12.10 Meeting Creation Rules**

A meeting shall be created only when:

- Booking is confirmed.
- Instructor is active.
- Student is active.
- Lesson time is valid.
- Payment is complete where required.
- Demo eligibility is valid where applicable.

Meeting creation shall be triggered automatically.

If meeting creation fails, the system shall retry automatically and notify administrators if the issue persists.

# **12.11 Meeting Types**

The platform supports the following meeting types.

## **Demo Lesson Meeting**

Created for a free demo lesson.

Rules:

- No payment required.
- Meeting created after demo confirmation.
- Meeting duration follows demo configuration.
- Meeting may contribute to demo-to-paid conversion analytics.

## **Paid Lesson Meeting**

Created for confirmed paid lessons.

Rules:

- Payment or wallet deduction required before meeting creation.
- Meeting duration follows selected lesson duration.
- Meeting contributes to instructor earnings and analytics after completion.

## **Recurring Lesson Meeting**

Created for recurring lesson occurrences.

Rules:

- Each occurrence may have its own meeting record.
- Meeting creation timing is configurable.
- Meeting access depends on payment or wallet deduction status.
- If wallet balance is insufficient, meeting access may be withheld according to policy.

# **12.12 Meeting Creation Timing**

The system may create meetings:

## **Option A - Immediately After Booking Confirmation**

Recommended for demo lessons and paid single lessons.

## **Option B - Before Each Recurring Lesson**

Recommended for recurring lessons to avoid generating unnecessary future meeting links.

## **Option C - Batch Generation**

Future option for package or subscription-based lessons.

Version 1 recommendation:

- Demo: create immediately after confirmation.
- Paid single: create immediately after payment confirmation.
- Recurring: create each occurrence after wallet/payment confirmation according to configured timing.

# **12.13 Meeting Link Visibility**

Meeting links shall be visible only to authorized users.

Authorized users include:

- The assigned student.
- The assigned instructor.
- Authorized administrators.
- Authorized observers.

Meeting links shall not be visible to:

- Guests.
- Unrelated students.
- Other instructors.
- Unauthorized staff.
- Public website visitors.

# **12.14 Meeting Link Access Window**

The platform may restrict when meeting links become visible.

Example:

- Meeting link visible 15 minutes before start time.
- Meeting link remains visible until a configured time after lesson end.

This reduces unnecessary sharing and improves access control.

Visibility rules are configurable.

# **12.15 Join Lesson Flow - Student**

Student flow:

Student logs in

│

▼

Opens dashboard

│

▼

Views upcoming lesson

│

▼

Clicks Join Lesson

│

▼

System validates access

│

▼

Student joins meeting

The student should not need the instructor's personal email or phone number.

# **12.16 Join Lesson Flow - Instructor**

Instructor flow:

Instructor logs in

│

▼

Opens lesson dashboard

│

▼

Views upcoming lesson

│

▼

Clicks Start / Join Class

│

▼

System validates access

│

▼

Instructor joins meeting

The instructor should not manually send external meeting links.

# **12.17 Admin Observer Access**

Authorized administrators may join live lessons as observers for quality assurance.

Rules:

- Admin observer access must be permission-controlled.
- Admin observer activity must be logged.
- Student and instructor should be informed when an admin observer joins, where required by platform policy.
- Observer should not interrupt the lesson unless authorized.
- Observer should normally join muted and camera-off.
- Observer access should be used for quality assurance, training, investigation, or operational review.

# **12.18 Recording Strategy**

Recording is an important future asset for quality, review, and AI.

## **Version 1 Recording Storage**

Initially, recordings may be stored in platform-managed Google Drive.

Rules:

- Recordings belong to the platform.
- Recordings must not be stored in instructor personal drives.
- Recording access is permission-controlled.
- Recording metadata is linked to the booking.
- Recording retention policy is configurable.

## **Future Recording Storage**

Future versions may migrate recordings to:

- S3-compatible object storage
- Private cloud storage
- CDN-backed signed URLs
- Region-specific storage

The future recommended architecture is:

Meeting Recording

│

▼

Platform Storage

│

▼

Private Bucket

│

▼

Signed Access URL

│

▼

Retention Policy

# **12.19 Recording Consent**

The platform should clearly inform participants when a lesson may be recorded.

Recording consent should be included in:

- Terms of Service
- Booking confirmation
- Meeting interface notice
- Privacy policy

Where required, explicit participant consent may be collected before recording begins.

# **12.20 Recording Access**

Recording access may be granted to:

- Student
- Instructor
- Administrator
- Quality assurance team
- AI processing service in future

Access rules are configurable.

For Version 1, recording visibility may be limited to administrators only or expanded to students based on policy.

# **12.21 Recording Retention**

Recording retention must be configurable.

Examples:

- 7 days
- 30 days
- 90 days
- 180 days
- Custom retention period

After expiration, recordings may be archived or deleted according to platform policy.

Recording metadata may remain even after file deletion.

# **12.22 Attendance Tracking**

The platform should track attendance wherever supported by the meeting provider.

Attendance data may include:

- Student joined
- Instructor joined
- Join time
- Leave time
- Total duration
- Rejoin count
- Meeting duration
- Participant status

Attendance data supports:

- Completion
- No-show handling
- Instructor analytics
- Student analytics
- Settlement decisions
- Quality assurance

# **12.23 No-Show Support**

Meeting attendance contributes to no-show handling.

## **Student No-Show**

If the student does not join within the configured grace period:

- Booking may be marked Student No-Show.
- Instructor may confirm no-show.
- Attendance data may support the outcome.

## **Instructor No-Show**

If the instructor does not join within the configured grace period:

- Booking may be marked Instructor No-Show.
- Student refund may be triggered.
- Admin notification may be sent.

## **Both Absent**

If neither participant joins:

- Booking may be marked Missed.
- Policy determines next action.

# **12.24 Meeting Security**

Meeting access must follow strict security policies.

Rules:

- Meeting links must not be publicly accessible.
- Meeting links must not appear on public pages.
- Access requires authenticated platform session where possible.
- Meeting links should be generated by the platform.
- Meeting metadata should be stored securely.
- Access events should be logged.
- Expired or cancelled meetings should not remain active.

# **12.25 Contact Leakage Prevention**

The platform shall reduce the risk of students and instructors bypassing STEM Learning.

Measures include:

- Platform-owned meetings.
- No direct email exposure where possible.
- No phone number exposure.
- Platform-controlled chat.
- Meeting links visible only after confirmation.
- Terms prohibiting off-platform solicitation.
- Admin monitoring for abuse.
- Future custom classroom with stronger controls.

The platform cannot guarantee complete prevention of contact exchange during a live video call, but it shall reduce exposure and enforce business policy.

# **12.26 Meeting Updates**

If a booking is rescheduled:

- Meeting time must be updated where provider supports it.
- If update is not possible, old meeting should be cancelled and a new one created.
- Student and instructor should receive updated information.
- Booking timeline should record the change.

If a booking is cancelled:

- Meeting should be cancelled or invalidated.
- Meeting link should no longer be accessible.
- Notifications should be sent.

# **12.27 Meeting Failure Handling**

Possible meeting failures include:

- Meeting creation failed.
- Meeting provider unavailable.
- Join link failed.
- Recording failed.
- Attendance fetch failed.
- Webhook failed.

The system should:

- Retry automatically where possible.
- Notify administrators if failure persists.
- Provide fallback instructions if configured.
- Preserve booking record.
- Log failure details.

# **12.28 Technical Issue Reporting**

Students and instructors may report meeting technical issues.

Examples:

- Could not join meeting.
- Audio not working.
- Video not working.
- Internet disconnected.
- Meeting link invalid.
- Recording unavailable.

Technical issue reports may affect:

- Completion decision
- Refund decision
- Reschedule decision
- Instructor performance
- Platform support workflow

# **12.29 Virtual Classroom Experience**

The student and instructor experience should be simple.

The lesson page should display:

- Lesson title
- Subject
- Instructor/student name
- Date and time
- Countdown
- Join button
- Meeting status
- Support link
- Technical issue report option
- Homework link after completion
- Review link after completion

# **12.30 Meeting Provider Configuration**

Administrators shall be able to configure:

- Active meeting provider
- Platform meeting account
- Recording enabled/disabled
- Recording storage location
- Meeting link visibility window
- Admin observer permissions
- Attendance tracking enabled/disabled
- Retry rules
- Failure notification rules

# **12.31 Functional Requirements**

## **Meeting Creation**

### **Meeting Creation After Booking**

**Priority:** Critical

The system shall automatically create a meeting after a booking is confirmed.

### **Demo Meeting Creation**

**Priority:** Critical

The system shall create meetings for confirmed demo lessons.

### **Paid Meeting Creation**

**Priority:** Critical

The system shall create meetings for confirmed paid lessons only after payment or wallet requirements are satisfied.

### **Recurring Meeting Creation**

**Priority:** High

The system shall create meetings for recurring lesson occurrences according to configured timing.

### **Platform-Owned Meeting**

**Priority:** Critical

All standard lesson meetings shall be created under platform ownership rather than instructor ownership.

## **Meeting Access**

### **Student Join Access**

**Priority:** Critical

The system shall allow the assigned student to join the meeting through the platform.

### **Instructor Join Access**

**Priority:** Critical

The system shall allow the assigned instructor to join or start the meeting through the platform.

### **Meeting Link Visibility**

**Priority:** Critical

The system shall restrict meeting link visibility to authorized users only.

### **Meeting Access Window**

**Priority:** High

The system shall support configurable meeting link visibility windows before and after lesson time.

### **Cancelled Meeting Access**

**Priority:** Critical

The system shall prevent access to cancelled meeting links where technically supported.

## **Admin Observer**

### **Admin Observer Access**

**Priority:** High

The system shall allow authorized administrators to join live classes as observers.

### **Observer Activity Logging**

**Priority:** Critical

The system shall record admin observer access in activity logs.

### **Observer Notice**

**Priority:** Medium

The system shall notify participants when an administrator joins as observer, where required by platform policy.

## **Recording**

### **Meeting Recording**

**Priority:** High

The system shall support meeting recording where the active provider supports it.

### **Platform Recording Ownership**

**Priority:** Critical

Meeting recordings shall be owned by the platform and not by instructor personal accounts.

### **Google Drive Recording Storage**

**Priority:** High

Version 1 shall support storing recordings in a platform-managed Google Drive account where configured.

### **Recording Metadata**

**Priority:** Critical

The system shall store recording metadata linked to the booking and meeting record.

### **Recording Access Control**

**Priority:** Critical

The system shall restrict recording access according to configured permissions.

### **Recording Retention**

**Priority:** High

The system shall support configurable recording retention policies.

## **Attendance**

### **Attendance Tracking**

**Priority:** High

The system shall capture attendance data where supported by the meeting provider.

### **Join Time Tracking**

**Priority:** High

The system shall track participant join times where available.

### **Leave Time Tracking**

**Priority:** High

The system shall track participant leave times where available.

### **Attendance-Based No-Show Support**

**Priority:** High

The system shall use attendance data to support no-show decisions where available.

## **Meeting Updates**

### **Rescheduled Meeting Update**

**Priority:** Critical

The system shall update or recreate meeting details when a booking is rescheduled.

### **Cancelled Meeting Handling**

**Priority:** Critical

The system shall cancel or invalidate meeting access when a booking is cancelled.

### **Meeting Failure Retry**

**Priority:** High

The system shall retry meeting creation when provider integration fails.

### **Admin Failure Notification**

**Priority:** High

The system shall notify administrators when meeting creation or access failures persist.

## **Technical Issues**

### **Technical Issue Reporting**

**Priority:** High

The system shall allow students and instructors to report meeting-related technical issues.

### **Technical Issue Review**

**Priority:** Medium

The system shall allow authorized administrators to review reported meeting issues.

## **Configuration**

### **Provider Configuration**

**Priority:** Critical

Administrators shall be able to configure the active meeting provider.

### **Recording Configuration**

**Priority:** High

Administrators shall be able to enable or disable recording globally or by policy.

### **Observer Configuration**

**Priority:** High

Administrators shall be able to configure which roles may observe live classes.

# **12.32 Business Rules**

- Meetings shall be created only for confirmed bookings.
- Paid lesson meetings shall be created only after payment or wallet requirements are satisfied.
- All standard lesson meetings shall be platform-owned.
- Instructor-created personal meeting links shall not be used for standard platform bookings.
- Meeting links shall not be publicly visible.
- Only assigned participants and authorized administrators may access meeting details.
- Admin observer access shall be permission-controlled and logged.
- Recordings, when enabled, shall be owned by the platform.
- Recording access shall follow platform permission rules.
- Cancelled bookings shall not retain active join access where provider limitations allow restriction.
- Meeting attendance data may support completion, no-show, refund, and settlement decisions.
- The platform shall reduce, but cannot fully eliminate, off-platform contact exchange risk during live video interactions.

# **12.33 Validation Rules**

- A meeting cannot be created without a confirmed booking.
- A meeting must reference one booking or booking occurrence.
- Meeting start time must match the confirmed booking start time unless explicitly updated through rescheduling.
- Only authorized users may access meeting join links.
- Recording retention period must be a valid configured value.
- Admin observer access requires explicit permission.
- A cancelled booking cannot expose an active platform join action.

# **12.34 User Workflows**

## **Meeting Creation After Booking**

- Booking is confirmed.
- System determines active meeting provider.
- System creates meeting under platform account.
- System stores meeting metadata.
- System links meeting to booking.
- System notifies student and instructor.
- Meeting appears in dashboards.

## **Student Joins Lesson**

- Student logs in.
- Student opens upcoming lesson.
- System verifies student is assigned to booking.
- System checks meeting link visibility window.
- Student clicks Join Lesson.
- Access event is logged.
- Student joins meeting.

## **Instructor Joins Lesson**

- Instructor logs in.
- Instructor opens today's lessons.
- System verifies instructor assignment.
- Instructor clicks Start or Join Lesson.
- Access event is logged.
- Instructor joins meeting.

## **Admin Observes Lesson**

- Authorized admin opens live lesson monitor.
- Admin selects active lesson.
- System verifies observer permission.
- System records observer access.
- Admin joins meeting as observer.
- Participants are notified where required.

## **Recording Storage**

- Meeting is recorded.
- Recording becomes available from provider.
- System receives recording metadata or fetches it.
- Recording is stored or linked in platform-managed storage.
- Recording metadata is associated with booking.
- Access permissions are applied.
- Retention policy is scheduled.

## **Meeting Rescheduled**

- Booking is rescheduled.
- System checks provider capability.
- System updates existing meeting or creates new meeting.
- Old meeting is cancelled or invalidated where possible.
- Dashboards are updated.
- Notifications are sent.

# **12.35 Exception Handling**

## **Meeting Creation Failed**

The system shall retry meeting creation and notify administrators if the issue persists.

## **Meeting Provider Unavailable**

The system shall notify administrators and may display a temporary support message to affected users.

## **Student Cannot Join**

The student may report a technical issue within the configured reporting window.

## **Instructor Cannot Join**

The instructor may report a technical issue, but repeated failures may affect instructor performance metrics.

## **Recording Failed**

The system shall record the failure and notify administrators if recording was required.

## **Attendance Data Unavailable**

The system may fall back to instructor completion confirmation and participant reports.

## **Admin Observer Cannot Join**

The system shall show a permission or provider limitation message and record the failed access attempt where appropriate.

# **12.36 Notifications**

Meeting-related notifications include:

## **Student**

- Meeting created
- Meeting link available
- Lesson reminder
- Meeting rescheduled
- Meeting cancelled
- Recording available, if enabled
- Technical issue update

## **Instructor**

- Meeting created
- Lesson starting soon
- Meeting rescheduled
- Meeting cancelled
- Recording available, if enabled
- Attendance issue detected
- Completion pending

## **Administrator**

- Meeting creation failed
- Recording failed
- Provider integration error
- Admin observer access logged
- Technical issue reported
- Repeated instructor meeting issues

# **12.37 Reports & Analytics**

Meeting analytics may include:

- Total meetings created
- Successful meetings
- Failed meeting creation
- Meeting provider reliability
- Student attendance rate
- Instructor attendance rate
- Average join delay
- Average lesson duration
- No-show rate
- Recording success rate
- Technical issue rate
- Admin observer sessions
- Country-wise meeting activity
- Subject-wise meeting activity

# **12.38 Administrative Configuration**

Administrators shall be able to configure:

- Active meeting provider
- Platform meeting account
- Meeting creation timing
- Meeting link visibility window
- Recording enabled/disabled
- Recording storage provider
- Recording retention period
- Recording access policy
- Admin observer roles
- Attendance tracking behavior
- Technical issue reporting window
- Provider failure retry policy
- Meeting notification templates

# **12.39 Acceptance Criteria**

- Meetings are automatically created after valid booking confirmation.
- Paid lesson meetings are not created before payment or wallet requirements are satisfied.
- Meeting links are visible only to authorized participants and administrators.
- All standard meetings are created under platform ownership.
- Admin observers can join live classes only with proper permission and logging.
- Recordings, when enabled, are stored under platform control and linked to booking records.
- Attendance data supports no-show and completion workflows where available.
- Meeting failures are logged, retried where possible, and escalated to administrators when unresolved.

# **12.40 Future Enhancements**

The Virtual Classroom & Meeting Management module is designed to support:

- Zoom integration.
- Programmable classroom using 100ms, Daily.co, or LiveKit.
- Native WebRTC classroom.
- S3-compatible recording storage.
- AI transcription.
- AI lesson summary.
- AI homework generation from lesson content.
- AI attendance insights.
- Real-time whiteboard.
- In-class chat moderation.
- Screen sharing controls.
- Breakout rooms for future group classes.
- Instructor quality scoring from meeting behavior.
- Automated recording retention.
- Region-specific storage compliance.
- Live classroom monitoring dashboard.
- Mobile classroom experience.

# **12.41 Chapter Summary**

The Virtual Classroom & Meeting Management module ensures that every online lesson is delivered through a secure, platform-controlled, and auditable meeting experience.

The platform-owned meeting strategy protects the business relationship, reduces off-platform leakage risk, supports automated attendance tracking, enables admin quality assurance, and prepares the system for future AI-powered educational capabilities.

Version 1 may begin with Google Workspace and Google Drive-based recording storage, while the architecture remains flexible enough to support Zoom, programmable video platforms, and S3-compatible storage in future releases.

PART D

# **PART D - FINANCIAL**

# **CHAPTER 13 - WALLET MANAGEMENT & LEDGER SYSTEM**

# **13.1 Introduction**

The Wallet Management & Ledger System governs how student balances, wallet recharges, booking deductions, refunds, referral credits, manual adjustments, and financial transaction histories are managed within the STEM Learning platform.

The wallet is a core financial feature of the platform. It improves booking speed, supports recurring lessons, enables wallet-only refunds, simplifies referral rewards, and reduces repeated payment friction for students.

The wallet must be designed as a financial ledger, not merely as a stored balance. Every credit and debit must be recorded permanently, traceably, and immutably.

The Wallet Management & Ledger System integrates with Booking, Payments, Recurring Lessons, Cancellation, Refunds, Referrals, Admin Adjustments, Reports, Notifications, and future Subscription or Package modules.

# **13.2 Purpose**

The purpose of this module is to provide a secure, auditable, and country-aware wallet system that allows students to maintain prepaid balance and use that balance for platform services.

The module must ensure:

- Accurate wallet balances
- Permanent transaction history
- Currency-safe operations
- Wallet-based lesson payments
- Wallet-only refunds
- Referral reward credits
- Admin-controlled manual adjustments
- Financial auditability
- Support for recurring lesson auto-deductions

# **13.3 Business Objectives**

The Wallet Management & Ledger System shall support the following business objectives:

- Reduce payment friction for students.
- Support recurring lesson payments.
- Enable wallet-only refund policy.
- Support student referral rewards.
- Maintain complete financial traceability.
- Prevent negative balances.
- Support country-specific currencies.
- Improve student retention through prepaid balance.
- Reduce dependency on repeated gateway transactions.
- Prepare the platform for future packages and subscriptions.

# **13.4 Scope**

This chapter covers:

## **Student Wallet**

- Wallet creation
- Wallet balance
- Wallet currency
- Wallet recharge
- Wallet deduction
- Wallet refund
- Referral credit
- Wallet transaction history

## **Ledger**

- Immutable wallet transactions
- Credit entries
- Debit entries
- Transaction reference
- Booking reference
- Payment reference
- Refund reference
- Admin adjustment reference

## **Recurring Payments**

- Wallet balance check
- Auto deduction before lesson
- Low balance reminders
- Failed deduction handling

## **Administration**

- Wallet search
- Wallet transaction review
- Manual credit
- Manual debit
- Audit trail
- Wallet reports

# **13.5 Wallet Philosophy**

The wallet should be treated as a financial account within the platform.

It is not a simple editable balance.

Every wallet balance must be derived from transaction history.

The platform must never silently change a wallet balance without creating a ledger transaction.

The wallet exists to support:

- Faster booking
- Recurring learning
- Refund management
- Referral rewards
- Financial transparency

# **13.6 Wallet Ownership**

Every student shall have one wallet associated with their account.

The wallet belongs to the student and operates in the student's assigned billing currency.

Rules:

- One student has one wallet per billing currency in Version 1.
- Version 1 does not support multiple wallet currencies per student.
- Wallet currency is derived from the student's billing country.
- Wallet balance cannot be transferred to another student.
- Wallet balance cannot become negative.
- Wallet history remains preserved even if the student account is archived.

# **13.7 Wallet Currency**

Wallet currency is determined by the student's billing country.

Examples:

| **Student Country** | **Wallet Currency** |
| ------------------- | ------------------- |
| India               | INR                 |
| ---                 | ---                 |
| United States       | USD                 |
| ---                 | ---                 |
| United Kingdom      | GBP                 |
| ---                 | ---                 |
| Canada              | CAD                 |
| ---                 | ---                 |
| Australia           | AUD                 |
| ---                 | ---                 |

The wallet currency affects:

- Recharge amount
- Booking deduction
- Refund credit
- Referral reward
- Invoice currency
- Wallet reports

Cross-currency wallet transactions are not supported in Version 1.

# **13.8 Wallet Balance**

The wallet balance represents the available amount the student can use for eligible platform services.

The balance is affected by:

## **Credits**

- Wallet recharge
- Booking refund
- Referral reward
- Admin manual credit
- Promotional credit

## **Debits**

- Lesson booking
- Recurring lesson deduction
- Admin manual debit
- Expired promotional credit, if configured in future

Wallet balance should be displayed clearly in the student's dashboard.

# **13.9 Ledger System**

The wallet ledger is the permanent transaction record behind every wallet balance.

Every wallet transaction shall include:

- Transaction reference number
- Student
- Wallet
- Currency
- Transaction type
- Credit or debit direction
- Amount
- Previous balance
- New balance
- Related booking, if applicable
- Related payment, if applicable
- Related referral, if applicable
- Related admin action, if applicable
- Transaction status
- Transaction timestamp
- Created by system or user
- Reason or description

Ledger entries must be immutable after creation.

If correction is required, the system shall create a reversing transaction rather than editing the original transaction.

# **13.10 Wallet Transaction Types**

The wallet shall support the following transaction types.

## **Recharge**

Student adds money through a payment gateway.

Direction: Credit

## **Booking Payment**

Student pays for a lesson using wallet balance.

Direction: Debit

## **Recurring Lesson Deduction**

Wallet deduction for a scheduled recurring lesson occurrence.

Direction: Debit

## **Cancellation Refund**

Refund credited to wallet after eligible cancellation.

Direction: Credit

## **Instructor No-Show Refund**

Refund credited to wallet when instructor fails to attend according to policy.

Direction: Credit

## **Referral Reward**

Reward credited after referral eligibility is satisfied.

Direction: Credit

## **Promotional Credit**

Credit granted under a platform campaign.

Direction: Credit

## **Admin Manual Credit**

Administrator adds wallet balance with required reason.

Direction: Credit

## **Admin Manual Debit**

Administrator reduces wallet balance with required reason.

Direction: Debit

## **Reversal**

Transaction created to correct a previous wallet transaction.

Direction: Credit or Debit depending on correction.

# **13.11 Wallet Recharge**

Students may add funds to their wallet using the configured payment gateway for their country.

Recharge flow:

Student opens wallet

│

▼

Selects recharge amount

│

▼

System validates amount

│

▼

Payment initiated

│

▼

Payment gateway confirms success

│

▼

Wallet credited

│

▼

Ledger transaction created

│

▼

Receipt generated

Wallet recharge is confirmed only after payment gateway success.

# **13.12 Minimum and Maximum Recharge Limits**

Administrators may configure:

- Minimum recharge amount
- Maximum recharge amount
- Suggested recharge amounts
- Country-specific recharge limits
- Currency-specific recharge precision

Example:

| **Country** | **Currency** | **Minimum Recharge** |
| ----------- | ------------ | -------------------- |
| India       | INR          | ₹500                 |
| ---         | ---          | ---                  |
| USA         | USD          | \$10                 |
| ---         | ---          | ---                  |
| UK          | GBP          | £10                  |
| ---         | ---          | ---                  |

# **13.13 Wallet Payment for Lessons**

Students may use wallet balance to pay for eligible paid lessons.

Rules:

- Wallet balance must be sufficient.
- Wallet deduction must occur before booking confirmation.
- Booking cannot be confirmed if wallet deduction fails.
- Ledger transaction must reference the booking.
- Deduction amount must match resolved student-facing price.
- Wallet deduction must be recorded before meeting creation.

# **13.14 Wallet for Recurring Lessons**

Wallet is the recommended Version 1 payment model for recurring lessons.

Recurring model:

Student recharges wallet

│

▼

Student creates recurring lesson schedule

│

▼

Before each lesson occurrence

│

▼

System checks wallet balance

│

▼

Wallet debited

│

▼

Lesson confirmed or remains payment pending

This avoids subscription complexity while still supporting recurring learning.

# **13.15 Recurring Deduction Timing**

The system shall support configurable wallet deduction timing.

Examples:

- Immediately when occurrence is generated
- 24 hours before lesson
- 12 hours before lesson
- 6 hours before lesson
- 2 hours before lesson

Recommended Version 1 behavior:

- Deduct wallet before each lesson occurrence according to configured timing.
- Send reminder if balance is insufficient.
- Do not allow unpaid recurring occurrences to proceed.

# **13.16 Low Wallet Balance**

The system shall monitor wallet balances for recurring students.

Low balance notifications may be sent when:

- Balance is below one upcoming lesson amount.
- Balance is below configured threshold.
- Balance is insufficient for the next recurring lesson.
- Balance is insufficient for upcoming weekly lessons.

Notifications may include recharge link and upcoming lesson impact.

# **13.17 Insufficient Wallet Balance**

If wallet balance is insufficient for an upcoming lesson:

The system may:

- Mark occurrence as payment pending.
- Notify student.
- Allow recharge before cutoff.
- Notify instructor if lesson may not proceed.
- Cancel or pause occurrence after configured grace period.
- Preserve recurring plan unless policy cancels it.

Recommended Version 1 behavior:

- Notify student.
- Keep occurrence pending until payment cutoff.
- If still unpaid, cancel only that occurrence.
- Keep future recurring schedule active.

# **13.18 Wallet Refund Policy**

Version 1 refund policy is wallet-only.

Eligible refunds shall be credited to wallet, not original payment method.

Refund sources include:

- Student cancellation within eligible window
- Instructor no-show
- Admin-approved exception
- Technical issue resolution
- Booking failure after payment

Refund rules:

- Refund currency matches original wallet/payment currency.
- Refund creates a credit ledger transaction.
- Original booking/payment transaction remains unchanged.
- Refund is linked to original booking.

# **13.19 Referral Rewards**

Referral rewards are credited to wallet.

Referral reward configuration may include:

- Reward percentage
- Fixed reward amount
- Maximum rewarded lessons
- Minimum eligible paid lessons
- Campaign validity
- Country applicability
- Reward expiry, future
- Fraud checks

Your finalized business rule:

- Student referral only
- Reward may be calculated per eligible class
- Maximum reward up to 10 classes
- Suggested reward: 5% per eligible class
- Minimum eligibility: at least 1 paid class

Exact campaign values remain admin-configurable.

# **13.20 Promotional Credits**

The platform may support promotional wallet credits.

Examples:

- Launch bonus
- Country campaign
- Student retention credit
- Manual customer care credit

Promotional credits may have future restrictions such as:

- Expiry date
- Subject applicability
- Minimum booking amount
- Non-withdrawable status

Version 1 may treat promotional credits as standard wallet credits unless advanced restrictions are enabled.

# **13.21 Admin Wallet Adjustments**

Authorized administrators may perform wallet adjustments.

Adjustment types:

- Manual credit
- Manual debit
- Reversal
- Refund correction
- Goodwill credit

Rules:

- Admin must provide reason.
- Adjustment must create ledger transaction.
- Adjustment must be audit logged.
- Previous balance and new balance must be recorded.
- High-value adjustments may require approval in future.

# **13.22 Wallet Transaction Status**

Wallet transactions may have statuses such as:

- Pending
- Completed
- Failed
- Reversed
- Cancelled

Only completed transactions affect available wallet balance.

Pending transactions may be used during payment confirmation or internal processing.

# **13.23 Wallet Statement**

Students shall be able to view wallet statement.

Statement should include:

- Date
- Transaction type
- Description
- Credit amount
- Debit amount
- Balance after transaction
- Related booking or payment
- Status

Students should be able to filter by:

- Date range
- Credit
- Debit
- Booking payment
- Recharge
- Refund
- Referral reward

# **13.24 Wallet Security**

Wallet operations must follow strict security rules.

Rules:

- Wallet balance cannot be directly edited.
- Every balance change requires ledger entry.
- Every admin adjustment requires reason.
- Financial operations must be audit logged.
- Wallet debits require sufficient available balance.
- Sensitive transaction details are visible only to authorized users.

# **13.25 Wallet Dashboard Integration**

Student dashboard shall show:

- Wallet balance
- Recharge button
- Recent transactions
- Upcoming recurring deductions
- Low balance warning
- Refund credits
- Referral credits

Instructor dashboard does not show student wallet information.

Admin dashboard may show aggregated wallet metrics.

# **13.26 Admin Wallet Management**

Administrators shall be able to:

- Search wallets by student
- View wallet balance
- View transaction history
- Filter transactions
- Export wallet reports
- Perform manual adjustments
- Review refund credits
- Review referral credits
- Audit wallet activity

Admin interface must clearly distinguish between wallet balance and transaction history.

# **13.27 Wallet Reports**

Wallet reports may include:

- Total wallet balance liability
- Recharge volume
- Wallet usage volume
- Refund credits
- Referral credits
- Manual adjustments
- Country-wise wallet balance
- Currency-wise wallet balance
- Student wallet activity
- Low balance students
- Failed wallet deductions

# **13.28 Financial Integrity Principles**

The wallet ledger shall follow these principles:

- No silent balance changes
- No negative balances
- Immutable transactions
- Reversal instead of editing
- Currency consistency
- Complete audit trail
- Booking/payment linkage
- Admin accountability
- Historical preservation

# **13.29 Functional Requirements**

## **Wallet Creation**

### **Student Wallet Creation**

**Priority:** Critical

The system shall automatically create a wallet when a student account is created.

### **Wallet Currency Assignment**

**Priority:** Critical

The system shall assign the wallet currency based on the student's billing country.

### **Single Wallet per Billing Currency**

**Priority:** Critical

Version 1 shall maintain one active wallet per student billing currency.

## **Wallet Balance**

### **Wallet Balance Display**

**Priority:** Critical

The system shall display wallet balance to the student in their assigned billing currency.

### **Balance Derived from Ledger**

**Priority:** Critical

Wallet balance shall be derived from completed wallet ledger transactions.

### **Negative Balance Prevention**

**Priority:** Critical

The system shall prevent wallet balance from becoming negative.

## **Recharge**

### **Wallet Recharge**

**Priority:** Critical

The system shall allow students to recharge wallet using the payment gateway configured for their country.

### **Recharge Amount Validation**

**Priority:** Critical

The system shall validate recharge amount against configured minimum and maximum limits.

### **Recharge Credit**

**Priority:** Critical

The system shall credit wallet only after confirmed successful payment.

### **Recharge Receipt**

**Priority:** High

The system shall generate a receipt or payment record for successful wallet recharge.

## **Ledger**

### **Ledger Transaction Creation**

**Priority:** Critical

The system shall create a ledger transaction for every wallet credit and debit.

### **Immutable Ledger Entries**

**Priority:** Critical

The system shall prevent modification of completed wallet ledger entries.

### **Transaction Reference**

**Priority:** Critical

Every wallet transaction shall have a unique transaction reference.

### **Balance Snapshot**

**Priority:** Critical

Every wallet transaction shall record previous balance and new balance.

### **Related Entity Linkage**

**Priority:** High

Wallet transactions shall link to related booking, payment, referral, refund, or admin action where applicable.

## **Wallet Payments**

### **Lesson Wallet Payment**

**Priority:** Critical

The system shall allow students to pay for eligible paid lessons using wallet balance.

### **Wallet Debit Before Booking Confirmation**

**Priority:** Critical

Wallet deduction shall occur before paid booking confirmation.

### **Wallet Payment Failure**

**Priority:** Critical

The system shall prevent booking confirmation if wallet deduction fails.

## **Recurring Lessons**

### **Recurring Wallet Deduction**

**Priority:** Critical

The system shall support wallet deductions for recurring lesson occurrences.

### **Low Balance Detection**

**Priority:** High

The system shall detect insufficient wallet balance for upcoming recurring lessons.

### **Low Balance Notification**

**Priority:** High

The system shall notify students when wallet balance is insufficient or below configured threshold.

### **Pending Recurring Payment**

**Priority:** High

The system shall support payment-pending state for recurring lesson occurrences when wallet balance is insufficient.

## **Refunds**

### **Wallet Refund Credit**

**Priority:** Critical

The system shall credit eligible refunds to the student wallet.

### **Refund Linkage**

**Priority:** Critical

Wallet refund transactions shall link to the original booking or payment event.

### **Refund Currency Consistency**

**Priority:** Critical

Refunds shall be credited in the original transaction currency.

## **Referral & Promotional Credit**

### **Referral Reward Credit**

**Priority:** High

The system shall credit eligible referral rewards to the student wallet.

### **Promotional Credit**

**Priority:** Medium

The system shall support promotional wallet credits where enabled by administrators.

## **Admin Adjustments**

### **Admin Manual Credit**

**Priority:** High

Authorized administrators shall be able to manually credit a student wallet with a required reason.

### **Admin Manual Debit**

**Priority:** High

Authorized administrators shall be able to manually debit a student wallet with a required reason, subject to sufficient balance.

### **Adjustment Audit Log**

**Priority:** Critical

Every manual wallet adjustment shall be audit logged.

### **Reversal Transaction**

**Priority:** High

The system shall support reversing wallet transactions through separate ledger entries rather than editing original records.

## **Wallet Statement**

### **Student Wallet Statement**

**Priority:** Critical

Students shall be able to view their wallet transaction history.

### **Wallet Statement Filters**

**Priority:** Medium

Students shall be able to filter wallet transactions by date range and transaction type.

## **Admin Wallet Management**

### **Admin Wallet Search**

**Priority:** High

Administrators shall be able to search student wallets.

### **Admin Transaction Review**

**Priority:** High

Administrators shall be able to view wallet transaction history.

### **Wallet Report Export**

**Priority:** Medium

Administrators shall be able to export wallet reports where permitted.

# **13.30 Business Rules**

- Every student shall have a wallet.
- Wallet currency shall be determined by student billing country.
- Version 1 shall not support cross-currency wallet transfers.
- Wallet balance shall never become negative.
- Wallet balance shall not be manually edited directly.
- Every wallet balance change shall create a ledger transaction.
- Completed ledger transactions shall be immutable.
- Corrections shall be handled through reversal transactions.
- Paid bookings using wallet shall require successful wallet debit before confirmation.
- Eligible refunds shall be credited to wallet only in Version 1.
- Referral rewards shall be credited to wallet according to active campaign rules.
- Admin wallet adjustments require a reason and audit log.
- Wallet history shall remain preserved even if the student account is archived.
- Only completed wallet transactions shall affect available balance.

# **13.31 Validation Rules**

- Recharge amount must be greater than zero.
- Recharge amount must satisfy configured minimum and maximum limits.
- Wallet debit amount must not exceed available balance.
- Wallet transaction currency must match wallet currency.
- Manual debit cannot exceed available balance.
- Admin adjustment reason is mandatory.
- Refund transaction must reference an eligible refund source.
- Referral reward must reference an eligible referral event.
- Transaction amount must use the configured decimal precision for the currency.

# **13.32 User Workflows**

## **Wallet Recharge**

- Student opens wallet page.
- Student selects or enters recharge amount.
- System validates amount.
- Student proceeds to payment gateway.
- Payment gateway returns success.
- System credits wallet.
- Ledger transaction is created.
- Receipt is generated.
- Student receives notification.

## **Wallet Payment for Lesson**

- Student selects wallet as payment method.
- System checks available balance.
- System debits wallet.
- Ledger transaction is created.
- Booking is confirmed.
- Meeting creation is triggered.
- Student receives booking confirmation.

## **Recurring Lesson Wallet Deduction**

- Upcoming recurring lesson reaches deduction window.
- System checks wallet balance.
- If balance is sufficient, wallet is debited.
- Lesson occurrence is confirmed.
- If balance is insufficient, student is notified.
- Lesson remains pending until configured cutoff.
- If unresolved, occurrence follows configured failure policy.

## **Wallet Refund**

- Booking cancellation or refund event occurs.
- System determines refund eligibility.
- System calculates refund amount.
- Wallet is credited.
- Refund ledger transaction is created.
- Student receives notification.
- Booking timeline is updated.

## **Admin Manual Credit**

- Administrator opens student wallet.
- Administrator selects manual credit.
- Administrator enters amount and reason.
- System validates permissions.
- Wallet is credited.
- Ledger transaction is created.
- Audit log is recorded.

## **Admin Manual Debit**

- Administrator opens student wallet.
- Administrator selects manual debit.
- Administrator enters amount and reason.
- System validates balance and permissions.
- Wallet is debited.
- Ledger transaction is created.
- Audit log is recorded.

# **13.33 Exception Handling**

## **Payment Successful but Wallet Credit Failed**

The system shall detect the mismatch, retry wallet credit, and notify administrators if unresolved.

## **Wallet Debit Failed During Booking**

The booking shall not be confirmed, and the student shall be shown a clear error.

## **Duplicate Gateway Callback**

The system shall prevent duplicate wallet credits for the same payment.

## **Insufficient Balance**

The system shall prevent debit and prompt recharge.

## **Admin Adjustment Error**

The system shall require correction through reversal transaction rather than editing the original transaction.

## **Currency Mismatch**

The system shall reject transaction if transaction currency does not match wallet currency.

## **Referral Abuse Detected**

The system may hold referral wallet credit for review according to referral policy.

# **13.34 Notifications**

Wallet-related notifications include:

## **Student**

- Wallet recharge successful
- Wallet recharge failed
- Wallet debited for booking
- Wallet debited for recurring lesson
- Refund credited
- Referral reward credited
- Low wallet balance
- Wallet adjustment made by admin
- Payment pending for upcoming recurring lesson

## **Administrator**

- Wallet credit failure after successful payment
- High-value manual adjustment
- Repeated refund activity
- Suspicious wallet activity
- Failed recurring deduction summary

# **13.35 Reports & Analytics**

Wallet reports shall support:

- Total wallet liability
- Wallet recharge amount
- Wallet debit amount
- Refund credits
- Referral credits
- Promotional credits
- Manual adjustments
- Country-wise wallet balance
- Currency-wise wallet balance
- Wallet-funded bookings
- Low balance students
- Recurring deduction failures
- Wallet transaction export

# **13.36 Administrative Configuration**

Administrators shall be able to configure:

- Wallet enabled/disabled
- Minimum recharge amount
- Maximum recharge amount
- Suggested recharge amounts
- Country-specific recharge limits
- Low balance threshold
- Recurring deduction timing
- Recurring payment cutoff
- Refund-to-wallet rules
- Referral wallet reward rules
- Manual adjustment permissions
- Wallet notification templates
- Wallet report access permissions

# **13.37 Acceptance Criteria**

- A wallet is automatically created for every student in the correct billing currency.
- Students can recharge wallet through the configured payment gateway.

### **AC-WAL-003**

- Wallet balance updates only through ledger transactions.
- Wallet balance never becomes negative.
- Students can use wallet balance to pay for eligible lessons.
- Recurring lessons can be paid through scheduled wallet deductions.
- Eligible refunds are credited to wallet and linked to original booking.
- Referral rewards are credited to wallet according to campaign rules.
- Administrators can perform manual wallet adjustments only with permissions, reason, and audit logging.
- Students and administrators can view complete wallet transaction history.

# **13.38 Future Enhancements**

The Wallet Management & Ledger System is designed to support:

- Lesson packages
- Subscription billing
- Promotional credit expiry
- Separate bonus wallet and cash wallet
- Multi-currency wallet support
- Wallet transfer restrictions
- Parent-funded learner wallet
- Corporate wallet
- Auto top-up
- Saved payment method recharge
- Wallet risk scoring
- Advanced fraud detection
- Wallet reconciliation automation
- Refund approval workflow
- Finance approval workflow for high-value adjustments
- Accounting software integration
- Tax-ready financial exports

# **13.39 Chapter Summary**

The Wallet Management & Ledger System is a critical financial foundation for STEM Learning. It enables faster bookings, supports recurring learning, simplifies refunds, powers referral rewards, and provides students with a transparent financial account.

By designing the wallet as an immutable ledger instead of a simple balance field, the platform ensures financial accuracy, auditability, and long-term scalability.

This module directly supports Booking, Payments, Referrals, Refunds, Reports, Recurring Lessons, and future Lesson Packages or Subscription Billing.

# **CHAPTER 14 - PAYMENT GATEWAY, CHECKOUT & INVOICE MANAGEMENT**

# **14.1 Introduction**

The Payment Gateway, Checkout & Invoice Management module governs how students make payments, how payment transactions are processed, how wallet recharges are completed, how paid lesson bookings are confirmed, and how invoices or receipts are generated within the STEM Learning platform.

For Version 1, STEM Learning shall use **Razorpay as the primary payment gateway** because the company is India-based and primary platform settlement is expected to operate in INR.

The payment system must be secure, reliable, auditable, and tightly integrated with Wallet, Booking, Refund, Referral, Reporting, and Financial Ledger modules.

This module does not replace the Wallet module. Instead, it works together with the Wallet module. Razorpay handles external payment collection, while the Wallet and Ledger system records internal platform balance, credits, debits, refunds, and usage history.

# **14.2 Purpose**

The purpose of this module is to provide a controlled and secure payment flow for:

- Wallet recharge
- Paid lesson booking
- Recurring lesson wallet funding
- Payment verification
- Payment failure handling
- Invoice generation
- Receipt generation
- Refund reference tracking
- Financial reporting
- Payment auditability

The system shall ensure that no paid booking is confirmed unless payment or wallet deduction has been successfully completed.

# **14.3 Business Objectives**

The Payment Gateway, Checkout & Invoice Management module shall support the following business objectives:

- Enable secure online payments through Razorpay.
- Support INR-based settlement for the India-based company.
- Allow students to recharge wallet.
- Allow students to pay for paid lessons.
- Reduce payment friction.
- Ensure every payment is traceable.
- Prevent duplicate payment credits.
- Support accurate receipts and invoices.
- Support financial reconciliation.
- Prepare for future multi-gateway and international payment expansion.

# **14.4 Scope**

This chapter covers:

## **Payment Gateway**

- Razorpay integration
- Payment initiation
- Payment verification
- Payment status tracking
- Payment callback handling
- Failed payment handling

## **Checkout**

- Wallet recharge checkout
- Paid lesson checkout
- Booking checkout
- Payment confirmation
- Checkout expiry

## **Invoice & Receipt**

- Payment receipt
- Booking invoice
- Wallet recharge receipt
- Refund reference
- Downloadable invoice
- Student billing details

## **Administration**

- Payment transaction search
- Payment status review
- Failed payment review
- Payment reconciliation
- Invoice management
- Financial reports

# **14.5 Payment Strategy**

Version 1 shall follow a **Razorpay-first payment strategy**.

The platform shall use Razorpay for:

- Wallet recharge payments
- Paid lesson payments, where direct payment is allowed
- Failed payment tracking
- Payment confirmation
- Gateway transaction reference storage

The recommended operating model for recurring lessons is:

Student recharges wallet through Razorpay

│

▼

Wallet credited after successful payment

│

▼

Recurring lessons deduct from wallet

│

▼

Internal ledger records every debit

This reduces repeated gateway payments and makes recurring learning smoother.

# **14.6 INR Settlement Strategy**

Because the company is India-based, INR shall be treated as the primary settlement and reporting currency for Version 1.

The platform may still display country-based pricing in future, but Version 1 financial settlement is optimized around INR.

Recommended Version 1 approach:

- Razorpay is the primary gateway.
- INR is the primary operating currency.
- Wallets may initially operate in INR.
- Financial reporting is primarily INR-based.
- International currency collection may be planned for future after business validation.

If multi-currency student pricing is enabled later, the system must clearly separate:

- Student display currency
- Payment collection currency
- Internal reporting currency
- Instructor compensation currency

For Version 1, this complexity should be avoided unless required at launch.

# **14.7 Payment Use Cases**

The payment system supports the following use cases.

## **Wallet Recharge**

Student adds balance to wallet using Razorpay.

## **Paid Lesson Checkout**

Student pays for a paid lesson directly through Razorpay or through wallet balance.

## **Recurring Lesson Funding**

Student recharges wallet to support recurring lesson auto-deductions.

## **Admin Payment Review**

Administrator reviews payment transaction history and reconciles payment status.

## **Invoice Download**

Student downloads receipt or invoice for completed payment.

# **14.8 Checkout Philosophy**

Checkout should be simple, fast, and trustworthy.

The student should always clearly understand:

- What they are paying for.
- Amount payable.
- Currency.
- Payment method.
- Booking or wallet purpose.
- Success/failure outcome.
- Refund destination.

Checkout should never confirm a paid booking unless payment or wallet deduction is complete.

# **14.9 Checkout Types**

## **Wallet Recharge Checkout**

Used when the student adds funds to wallet.

Flow:

Student opens wallet

│

▼

Selects recharge amount

│

▼

System validates amount

│

▼

Razorpay checkout opened

│

▼

Student completes payment

│

▼

System verifies payment

│

▼

Wallet credited

│

▼

Receipt generated

## **Paid Lesson Checkout**

Used when a student pays directly for a specific lesson.

Flow:

Student selects lesson

│

▼

System reserves slot

│

▼

System calculates price

│

▼

Student selects payment method

│

▼

Razorpay checkout opened

│

▼

Payment verified

│

▼

Booking confirmed

│

▼

Meeting created

## **Wallet Payment Checkout**

Used when student pays using wallet balance.

This does not require Razorpay at the time of booking.

Flow:

Student selects lesson

│

▼

System calculates price

│

▼

System checks wallet balance

│

▼

Wallet debited

│

▼

Booking confirmed

│

▼

Meeting created

# **14.10 Payment Lifecycle**

Every payment progresses through defined states.

Initiated

│

▼

Pending

│

▼

Success

Alternative outcomes:

Initiated

│

▼

Failed

Initiated

│

▼

Expired

Success

│

▼

Refund Referenced

Success

│

▼

Partially Adjusted

The platform must maintain the payment status history.

# **14.11 Payment Status Definitions**

## **Initiated**

The platform has created a payment attempt.

## **Pending**

The student has entered checkout, and the payment result is not yet confirmed.

## **Success**

Razorpay has confirmed successful payment, and the platform has verified the payment.

## **Failed**

Payment was rejected, cancelled, declined, or otherwise unsuccessful.

## **Expired**

Payment was not completed within the allowed checkout window.

## **Refund Referenced**

A refund-related event has been linked to the original payment or booking.

For Version 1, actual student refund is credited to wallet according to refund policy.

# **14.12 Payment Verification**

Payment verification is mandatory before any financial credit or paid booking confirmation.

The system shall verify:

- Payment reference
- Order/reference identifier
- Amount
- Currency
- Status
- Related student
- Related wallet recharge or booking
- Duplicate processing status

If verification fails, the payment shall not credit wallet or confirm booking.

# **14.13 Duplicate Payment Protection**

The system must prevent duplicate processing.

Duplicate protection applies to:

- Repeated callbacks
- Browser refresh
- User retries
- Payment success event received multiple times
- Network retry
- Manual admin review

A Razorpay payment reference must not credit wallet or confirm a booking more than once.

# **14.14 Payment Failure Handling**

If payment fails:

- Booking remains unconfirmed.
- Reserved slot may remain temporarily available until reservation expiry.
- Student may retry payment while reservation is valid.
- Student may choose wallet recharge or another allowed method.
- Failed payment attempt is recorded.
- No wallet credit is created.
- No paid meeting is created.

# **14.15 Checkout Expiry**

Checkout and slot reservations should have configurable expiry.

If payment is not completed before expiry:

- Payment attempt may be marked expired.
- Slot reservation is released.
- Booking is not confirmed.
- Student may restart checkout.

# **14.16 Wallet Recharge Confirmation**

Wallet recharge is confirmed only after payment success verification.

After verification:

- Payment record is marked successful.
- Wallet credit transaction is created.
- Wallet balance is updated.
- Receipt is generated.
- Student is notified.

If payment success is confirmed but wallet credit fails:

- System must retry wallet credit.
- Admin must be alerted if unresolved.
- Duplicate credit must be prevented.

# **14.17 Paid Booking Confirmation**

A paid booking is confirmed only after:

- Slot is still valid.
- Payment is successful or wallet debit is successful.
- Booking eligibility is valid.
- Pricing is valid.
- No conflict exists.

After confirmation:

- Booking status becomes confirmed.
- Meeting creation is triggered.
- Notifications are sent.
- Payment is linked to booking.
- Financial ledger is updated where applicable.

# **14.18 Razorpay Transaction Reference**

Every Razorpay payment must store gateway references.

Examples:

- Razorpay order reference
- Razorpay payment reference
- Payment status
- Payment method, where available
- Payment amount
- Currency
- Payment timestamp
- Related student
- Related wallet recharge or booking

Gateway references are required for reconciliation and support.

# **14.19 Payment Methods**

Razorpay may support multiple payment modes depending on configuration and availability.

The platform should display only enabled and supported payment methods.

Possible modes may include:

- UPI
- Card
- Net Banking
- Wallets
- Other Razorpay-supported methods

The SRS does not require the platform to control every payment method individually unless exposed through configuration.

# **14.20 Saved Payment Methods**

Saved payment methods are not required for Version 1.

Future versions may support saved payment methods if required for faster checkout or subscription-style payments.

For Version 1, wallet recharge is preferred for recurring learning.

# **14.21 Invoice and Receipt Strategy**

The platform shall generate receipts or invoices for successful payments.

Invoice/receipt may be generated for:

- Wallet recharge
- Paid lesson booking
- Booking payment
- Manual financial correction, where applicable

The generated document should include:

- Student name
- Billing country
- Payment amount
- Currency
- Payment date
- Payment reference
- Service description
- Booking reference, if applicable
- Wallet recharge reference, if applicable
- Platform business details
- Invoice/receipt number

Tax handling is out of scope for Version 1 unless required later.

# **14.22 Invoice Numbering**

Every invoice or receipt shall have a unique reference number.

The numbering format should be configurable.

Example:

STEM/INV/2026/000001

Invoices should remain immutable after generation.

If correction is required, the system should issue an adjustment or credit note in a future version rather than editing the original record.

# **14.23 Student Billing Details**

The system may collect billing details for invoice purposes.

Version 1 billing details may include:

- Student name
- Email
- Country
- State
- City
- Address, optional
- Postal code, optional

Billing requirements may be expanded later if tax compliance is introduced.

# **14.24 Refund References**

Version 1 student refunds are credited to wallet.

However, payment records should still maintain refund reference information.

Refund-related records may include:

- Original payment reference
- Booking reference
- Refund reason
- Refund amount
- Wallet credit transaction reference
- Admin action reference, if applicable

Original payment records must not be deleted or overwritten.

# **14.25 Reconciliation**

Administrators shall be able to reconcile platform payment records with Razorpay payment records.

Reconciliation should identify:

- Successful gateway payment but missing wallet credit
- Successful gateway payment but missing booking confirmation
- Failed gateway payment recorded as pending
- Duplicate callback attempts
- Amount mismatch
- Currency mismatch
- Unmatched transactions

Reconciliation is essential for financial accuracy.

# **14.26 Admin Payment Management**

Administrators should be able to:

- View all payment attempts
- Filter by status
- Search by student
- Search by payment reference
- Search by booking reference
- Search by wallet transaction
- Review failed payments
- Review pending payments
- View gateway response summary
- Trigger verification retry where permitted
- Export payment records

Admin actions must be permission-controlled and audit logged.

# **14.27 Payment Security**

The payment system shall follow strict security principles.

Rules:

- Payment confirmation must be verified server-side.
- Client-side success alone is not sufficient.
- Gateway references must be validated.
- Duplicate processing must be prevented.
- Payment amount must match platform-calculated amount.
- Payment currency must match expected currency.
- Sensitive gateway credentials must not be exposed to users.
- Payment records must be auditable.

# **14.28 Financial Data Integrity**

Payment data must remain historically accurate.

Rules:

- Successful payment records cannot be deleted.
- Payment amount cannot be edited after success.
- Corrections must be handled through separate adjustment records.
- Payment and wallet records must remain linked.
- Booking confirmation must reference payment or wallet transaction.
- Every financial action must be traceable.

# **14.29 Functional Requirements**

## **Gateway Configuration**

### **Razorpay Gateway Support**

**Priority:** Critical

The system shall support Razorpay as the primary payment gateway for Version 1.

### **Gateway Configuration**

**Priority:** Critical

Administrators shall be able to configure Razorpay gateway settings through secure platform configuration.

### **Gateway Enable/Disable**

**Priority:** High

Administrators shall be able to enable or disable Razorpay payment collection according to operational requirements.

## **Payment Initiation**

### **Wallet Recharge Payment Initiation**

**Priority:** Critical

The system shall allow students to initiate Razorpay payment for wallet recharge.

### **Paid Lesson Payment Initiation**

**Priority:** Critical

The system shall allow students to initiate Razorpay payment for paid lesson booking where direct payment is enabled.

### **Payment Attempt Record**

**Priority:** Critical

The system shall create a payment attempt record whenever checkout is initiated.

### **Payment Reference Generation**

**Priority:** Critical

Every payment attempt shall have a unique internal payment reference.

## **Payment Verification**

### **Payment Success Verification**

**Priority:** Critical

The system shall verify payment success before crediting wallet or confirming booking.

### **Amount Verification**

**Priority:** Critical

The system shall verify that paid amount matches expected platform-calculated amount.

### **Currency Verification**

**Priority:** Critical

The system shall verify that payment currency matches expected transaction currency.

### **Duplicate Callback Protection**

**Priority:** Critical

The system shall prevent duplicate processing of the same Razorpay payment reference.

### **Payment Failure Recording**

**Priority:** Critical

The system shall record failed payment attempts.

## **Checkout**

### **Checkout Expiry**

**Priority:** High

The system shall support configurable checkout expiry.

### **Retry Payment**

**Priority:** High

The system shall allow students to retry payment while the related reservation remains valid.

### **Checkout Failure Messaging**

**Priority:** High

The system shall display clear and user-friendly payment failure messages.

## **Wallet Recharge**

### **Recharge Confirmation**

**Priority:** Critical

The system shall credit student wallet only after successful payment verification.

### **Recharge Receipt**

**Priority:** High

The system shall generate a receipt for successful wallet recharge.

### **Recharge Failure Handling**

**Priority:** High

The system shall not credit wallet for failed or unverified recharge payments.

## **Booking Payment**

### **Booking Payment Confirmation**

**Priority:** Critical

The system shall confirm paid booking only after successful payment verification or wallet deduction.

### **Booking Payment Linkage**

**Priority:** Critical

The system shall link successful payment records to the related booking.

### **Failed Booking Payment**

**Priority:** Critical

The system shall not confirm a paid booking if payment fails.

## **Invoice & Receipt**

### **Invoice Generation**

**Priority:** High

The system shall generate invoice or receipt records for successful payments.

### **Invoice Number**

**Priority:** High

Every invoice or receipt shall have a unique reference number.

### **Invoice Download**

**Priority:** Medium

Students shall be able to download invoices or receipts for completed payments.

### **Invoice Immutability**

**Priority:** High

Generated invoice records shall not be silently edited after creation.

## **Refund Reference**

### **Refund Reference Tracking**

**Priority:** High

The system shall record wallet refund references against original payments or bookings.

### **Wallet Refund Linkage**

**Priority:** Critical

Payment records shall link to wallet refund transactions where applicable.

## **Admin**

### **Admin Payment Search**

**Priority:** High

Administrators shall be able to search and filter payment records.

### **Payment Reconciliation View**

**Priority:** High

Administrators shall be able to identify payment and wallet reconciliation issues.

### **Payment Export**

**Priority:** Medium

Administrators shall be able to export payment records where permitted.

### **Verification Retry**

**Priority:** Medium

Authorized administrators may trigger payment verification retry where appropriate.

# **14.30 Business Rules**

- Razorpay shall be the primary payment gateway for Version 1.
- INR shall be the primary settlement and reporting currency for Version 1.
- Payment must be verified before wallet credit or paid booking confirmation.
- Client-side payment success is not sufficient for confirming financial transactions.
- Duplicate gateway callbacks must not create duplicate wallet credits or duplicate booking confirmations.
- Failed payments shall not confirm bookings.
- Wallet recharge shall be credited only after successful verified payment.
- Every successful payment shall have a permanent payment record.
- Successful payment records shall not be deleted.
- Payment amount and currency must match expected platform-calculated values.
- Eligible refunds are credited to student wallet in Version 1.
- Invoice or receipt records shall be generated for successful payments where configured.
- Admin actions on payment records must be permission-controlled and audit logged.

# **14.31 Validation Rules**

- Payment amount must be greater than zero.
- Payment amount must match the expected payable amount.
- Payment currency must match expected transaction currency.
- Payment reference must be unique.
- A successful payment reference cannot be processed more than once.
- Wallet recharge amount must satisfy configured recharge limits.
- Paid booking payment must reference a valid reserved booking.
- Invoice number must be unique.
- Payment verification retry requires administrative permission.

# **14.32 User Workflows**

## **Wallet Recharge Through Razorpay**

- Student opens wallet.
- Student selects recharge amount.
- System validates recharge amount.
- System creates payment attempt.
- Razorpay checkout is initiated.
- Student completes payment.
- System verifies payment.
- Wallet is credited.
- Ledger transaction is created.
- Receipt is generated.
- Student receives confirmation.

## **Paid Lesson Checkout Through Razorpay**

- Student selects instructor, subject, duration, and slot.
- System reserves selected slot.
- System calculates payable amount.
- System creates payment attempt.
- Razorpay checkout is initiated.
- Student completes payment.
- System verifies payment.
- Booking is confirmed.
- Meeting creation is triggered.
- Invoice or receipt is generated.
- Notifications are sent.

## **Payment Failure During Booking**

- Student starts checkout.
- Razorpay payment fails or is cancelled.
- System records failed payment attempt.
- Booking remains unconfirmed.
- Slot remains reserved until reservation expiry.
- Student may retry payment if reservation is still valid.
- If reservation expires, slot is released.

## **Duplicate Callback Handling**

- Razorpay callback is received.
- System checks payment reference.
- If already processed, system ignores duplicate financial processing.
- Duplicate event may be logged.
- No duplicate wallet credit or booking confirmation occurs.

## **Payment Reconciliation Review**

- Administrator opens payment reconciliation view.
- Administrator filters unresolved payment issues.
- System displays mismatch records.
- Administrator reviews gateway and platform references.
- Administrator triggers permitted retry or marks for manual resolution.
- Action is audit logged.

# **14.33 Exception Handling**

## **Payment Success but Callback Delayed**

The system shall allow status verification to confirm payment later and complete the related workflow if valid.

## **Payment Success but Wallet Credit Failed**

The system shall retry wallet credit and alert administrators if unresolved.

## **Payment Success but Booking Confirmation Failed**

The system shall preserve payment record, notify administrators, and either confirm booking after retry or credit wallet according to resolution policy.

## **Payment Failed but User Claims Deduction**

The system shall allow administrators to search by payment reference and reconcile using gateway records.

## **Duplicate Payment**

If a student pays twice for the same intended transaction, the system shall prevent duplicate booking confirmation and support wallet credit or admin resolution according to policy.

## **Amount Mismatch**

If paid amount does not match expected amount, the system shall block automatic confirmation and notify administrators.

## **Currency Mismatch**

If payment currency does not match expected currency, the system shall block automatic confirmation and notify administrators.

## **Checkout Expired**

If checkout expires, booking remains unconfirmed and slot reservation is released.

# **14.34 Notifications**

Payment-related notifications include:

## **Student**

- Payment initiated
- Payment successful
- Payment failed
- Wallet recharge successful
- Wallet recharge failed
- Booking payment successful
- Invoice available
- Refund credited to wallet
- Payment pending

## **Administrator**

- Payment verification failed
- Amount mismatch
- Currency mismatch
- Successful payment but wallet credit failed
- Successful payment but booking confirmation failed
- Duplicate callback detected
- Reconciliation issue detected

# **14.35 Reports & Analytics**

Payment reports may include:

- Total payment attempts
- Successful payments
- Failed payments
- Payment success rate
- Wallet recharge volume
- Paid lesson payment volume
- Average payment amount
- Revenue by date
- Revenue by country
- Revenue by subject
- Razorpay transaction references
- Pending payments
- Reconciliation issues
- Refund references
- Invoice totals

# **14.36 Administrative Configuration**

Administrators shall be able to configure:

- Razorpay enabled/disabled
- Payment environment
- Checkout expiry
- Minimum wallet recharge
- Maximum wallet recharge
- Suggested recharge amounts
- Invoice numbering format
- Receipt template
- Payment notification templates
- Reconciliation access roles
- Verification retry permissions
- Payment failure messages

Sensitive gateway credentials shall be managed securely and shall not be exposed in normal administrative interfaces.

# **14.37 Acceptance Criteria**

- Students can recharge wallets using Razorpay.
- Wallet is credited only after verified successful payment.
- Students can pay for paid lessons using Razorpay where enabled.
- Paid bookings are confirmed only after payment verification or successful wallet deduction.
- Failed payments do not confirm bookings or credit wallets.
- Duplicate callbacks do not create duplicate credits or duplicate bookings.
- Students can access receipts or invoices for successful payments.
- Administrators can search, review, reconcile, and export payment records.
- Payment records remain traceable to wallet transactions, bookings, refunds, and invoices.

# **14.38 Future Enhancements**

The Payment Gateway, Checkout & Invoice Management module is designed to support future expansion, including:

- Stripe integration
- International card payments
- Multi-currency payments
- Saved payment methods
- Auto top-up
- Subscription billing
- Lesson package purchases
- Coupon application during checkout
- GST/tax invoice support
- Credit notes
- Automated reconciliation
- Accounting software integration
- Payment risk scoring
- Multi-gateway routing
- Country-specific gateway selection
- Corporate billing
- Parent account billing
- Installment payments

# **14.39 Chapter Summary**

The Payment Gateway, Checkout & Invoice Management module provides the external payment collection layer for STEM Learning.

For Version 1, Razorpay is the primary payment gateway, and INR is the primary settlement and reporting currency. This simplified strategy reduces payment complexity while supporting the core business needs of wallet recharge, paid lesson booking, receipts, invoices, and financial traceability.

The module ensures that every payment is verified, every successful payment is recorded, every wallet credit is traceable, and every paid booking is confirmed only after valid payment processing.

# **CHAPTER 15 - INSTRUCTOR EARNINGS, INCENTIVES, SETTLEMENT & WITHDRAWALS**

# **15.1 Introduction**

The Instructor Earnings, Incentives, Settlement & Withdrawals module governs how instructors earn money from completed lessons, how earnings are calculated, how incentives are applied, how balances become payable, and how withdrawals are processed.

This module is intentionally separated from student pricing.

In STEM Learning, students pay a platform-controlled lesson price. Instructors do not define or see student-facing prices. Instructor compensation is calculated internally according to platform-defined rules such as instructor category, experience, subject, lesson duration, performance, region, demo conversion, or administrator-approved pay configuration.

The module must support transparent instructor earnings while protecting the platform's commercial strategy, margin structure, and internal pricing model.

# **15.2 Purpose**

The purpose of this module is to provide a secure, auditable, and configurable earnings system for instructors.

The module must ensure:

- Instructors earn only for eligible completed lessons.
- Instructor pay is calculated independently from student-facing price.
- Instructor earnings are visible to instructors in a clear manner.
- Demo lessons may be unpaid or incentive-based according to policy.
- Incentives are configurable.
- Earnings become payable only after platform-defined conditions.
- Withdrawals are processed according to country-specific rules.
- Every earning, hold, adjustment, settlement, and withdrawal is traceable.

# **15.3 Business Objectives**

This module shall support the following business objectives:

- Maintain a clear instructor earning system.
- Protect student-facing pricing confidentiality.
- Support platform-controlled instructor pay.
- Encourage instructor quality and retention.
- Support demo-to-paid conversion incentives.
- Enable configurable payout rules.
- Maintain financial auditability.
- Prevent incorrect or duplicate instructor payments.
- Support country-specific withdrawal methods.
- Prepare for future instructor tiers, bonuses, and performance-based compensation.

# **15.4 Scope**

This chapter covers:

## **Instructor Earnings**

- Lesson earnings
- Demo incentives
- Paid lesson earnings
- Recurring lesson earnings
- Earnings status
- Earnings history

## **Incentives**

- Demo-to-paid conversion bonus
- Performance incentives
- Retention bonus
- Campaign-based bonus
- Admin-approved bonus

## **Settlement**

- Pending earnings
- Eligible earnings
- On-hold earnings
- Settled earnings
- Reversed earnings
- Settlement cycle

## **Withdrawals**

- Withdrawal request
- Withdrawal method
- Admin approval
- Payment processing
- Withdrawal status
- Withdrawal history

## **Administration**

- Instructor pay rules
- Manual adjustments
- Settlement review
- Withdrawal approval
- Financial reporting
- Audit logs

# **15.5 Core Financial Principle**

The platform shall follow this principle:

Student Price ≠ Instructor Pay

Student price is determined by:

- Country
- Currency
- Subject
- Education level
- Lesson duration
- Platform pricing policy
- Promotional rules

Instructor pay is determined by:

- Instructor compensation configuration
- Instructor category
- Lesson type
- Lesson duration
- Subject
- Experience level
- Performance rules
- Incentive policy
- Admin-approved pay settings

The system shall never expose platform margin or student pricing strategy to instructors.

# **15.6 Instructor Compensation Philosophy**

Instructor compensation should be:

- Clear to instructors.
- Controlled by the platform.
- Configurable by administrators.
- Independent from student price.
- Linked to completed teaching work.
- Auditable.
- Adjustable only through approved financial workflows.

The instructor should know what they will earn for eligible work, but not necessarily what the student paid.

# **15.7 Instructor Earnings Lifecycle**

Instructor earnings follow a lifecycle.

Lesson Booked

│

▼

Lesson Completed

│

▼

Earning Calculated

│

▼

Pending

│

▼

Eligible for Settlement

│

▼

Settlement Approved

│

▼

Withdrawable

│

▼

Withdrawal Requested

│

▼

Withdrawal Processed

│

▼

Paid

Alternative outcomes include:

Earning Calculated

│

▼

On Hold

Earning Calculated

│

▼

Reversed

Withdrawal Requested

│

▼

Rejected

# **15.8 Earning Status Definitions**

## **Pending**

The earning has been created but is not yet eligible for settlement.

Example:

- Lesson completed but settlement waiting period has not passed.

## **Eligible**

The earning is available for settlement based on platform rules.

## **On Hold**

The earning is temporarily blocked due to dispute, technical issue, admin review, no-show investigation, or policy violation.

## **Settled**

The earning has been included in a settlement batch or marked as payable.

## **Withdrawable**

The earning is available for instructor withdrawal.

## **Withdrawal Requested**

The instructor has requested payout.

## **Paid**

The withdrawal has been processed successfully.

## **Rejected**

The withdrawal request was rejected by an administrator or failed validation.

## **Reversed**

The earning was reversed due to correction, refund, dispute, duplicate entry, or admin-approved adjustment.

# **15.9 Lesson-Based Earnings**

Paid lesson earnings are generated after lesson completion.

Rules:

- Earnings are created only for eligible paid lessons.
- Demo lessons do not automatically create earnings unless incentive rules allow.
- Student no-show may still generate instructor earning according to policy.
- Instructor no-show shall not generate instructor earning.
- Technical issue cases may place earnings on hold.
- Cancelled lessons do not generate earnings unless policy defines a cancellation compensation rule.

# **15.10 Demo Lesson Earnings**

Demo lessons are free for students.

The platform may define one of the following demo instructor compensation policies:

## **Option A - No Demo Compensation**

Instructor does not earn direct payment for demo lessons.

## **Option B - Fixed Demo Compensation**

Instructor earns a fixed amount for eligible completed demos.

## **Option C - Demo-to-Paid Incentive**

Instructor earns a bonus only when the student converts from demo to paid lesson.

## **Option D - Hybrid**

Instructor receives a smaller demo amount plus conversion bonus.

Recommended Version 1 approach:

- No direct demo compensation initially, or
- Demo-to-paid conversion incentive only.

This keeps cost controlled while encouraging instructor conversion quality.

# **15.11 Paid Lesson Earnings**

For each completed paid lesson, the instructor earns according to configured pay rules.

Earning inputs may include:

- Instructor
- Student
- Booking
- Subject
- Education level
- Lesson duration
- Lesson type
- Completion status
- Instructor pay configuration
- Applicable incentive rules

The earning amount must be calculated and stored at the time the earning is created.

Future changes to instructor pay rules must not modify historical earnings.

# **15.12 Recurring Lesson Earnings**

Recurring lesson earnings are calculated per completed occurrence.

Rules:

- Each recurring occurrence is treated as a separate earning event.
- Earnings are created only after eligible completion.
- Wallet deduction or payment must be successful.
- If a recurring occurrence is unpaid, no instructor earning is generated.
- If an occurrence is cancelled, compensation depends on cancellation policy.
- If the student is a no-show, instructor compensation follows no-show policy.

# **15.13 Instructor Pay Configuration**

Administrators shall be able to configure instructor pay.

Possible pay models:

## **Fixed Rate per Lesson Duration**

Example:

- 30 minutes: ₹200
- 60 minutes: ₹400
- 90 minutes: ₹600

## **Subject-Based Rate**

Example:

- Mathematics 60 minutes: ₹400
- Python 60 minutes: ₹500
- English 60 minutes: ₹350

## **Instructor-Specific Rate**

A specific instructor has custom pay rules.

## **Instructor Tier-Based Rate**

Examples:

- Beginner Instructor
- Experienced Instructor
- Premium Instructor
- Expert Instructor

## **Country/Region-Based Rate**

Future-ready configuration for instructor country or operational region.

Recommended Version 1:

- Start with instructor-specific or tier-based pay rules.
- Keep subject and duration support.
- Avoid complex dynamic compensation until marketplace data is available.

# **15.14 Instructor Pay Visibility**

Instructors may view:

- Earnings per lesson
- Pending earnings
- Eligible earnings
- Withdrawable balance
- Withdrawal history
- Incentive earnings
- Adjustment history

Instructors shall not view:

- Student-facing price
- Platform margin
- Internal markup
- Other instructor earnings
- Admin-only financial notes
- Platform pricing rules

# **15.15 Earning Calculation Snapshot**

When an earning is created, the system shall store a calculation snapshot.

Snapshot may include:

- Instructor pay rule used
- Lesson duration
- Subject
- Lesson type
- Base earning amount
- Incentive amount
- Adjustment amount
- Total earning amount
- Currency
- Booking reference
- Completion timestamp
- Rule version or reference

This prevents future rule changes from altering past earnings.

# **15.16 Instructor Earnings Currency**

For Version 1, instructor earnings shall be primarily INR because the company is India-based and Razorpay settlement is INR-based.

Rules:

- Instructor earnings are recorded in INR for Version 1.
- Future multi-country instructor payouts may introduce country-specific payout currencies.
- Historical earning currency must remain unchanged.
- Currency conversion, if introduced later, must be stored as a separate financial calculation.

# **15.17 Instructor Incentives**

The platform may provide incentives to improve business outcomes.

Incentive examples:

- Demo-to-paid conversion bonus
- Student retention bonus
- High rating bonus
- Completion consistency bonus
- Low cancellation bonus
- Campaign-based bonus
- Referral-related instructor bonus, future
- Admin-approved goodwill bonus

Incentives must be configurable and auditable.

# **15.18 Demo-to-Paid Conversion Incentive**

A demo-to-paid conversion incentive rewards instructors when a student books a paid lesson after attending a demo.

Configuration may include:

- Conversion window
- Minimum paid lessons required
- Bonus amount
- Bonus percentage
- Maximum bonus per student
- Country applicability
- Subject applicability
- Instructor eligibility criteria

Example:

- Student completes demo.
- Student books first paid lesson within 7 days.
- Instructor receives ₹X bonus after paid lesson completion.

# **15.19 Performance Incentives**

Performance incentives may be based on:

- Lesson completion rate
- Student retention
- Average rating
- Review count
- Recurring student continuation
- Low cancellation rate
- Low instructor no-show rate

Performance incentives should not be automatically paid without clear configuration and auditability.

# **15.20 Campaign-Based Incentives**

Administrators may create temporary incentive campaigns.

Examples:

- Launch campaign
- Subject demand campaign
- Weekend availability campaign
- New instructor activation campaign
- Demo conversion campaign

Campaigns may define:

- Start date
- End date
- Eligible instructors
- Eligible subjects
- Eligible lesson types
- Incentive amount
- Maximum payout
- Conditions

# **15.21 Earning Holds**

Earnings may be placed on hold.

Reasons:

- Student dispute
- Technical issue
- Instructor no-show review
- Booking anomaly
- Refund investigation
- Policy violation
- Admin review
- Payment reconciliation issue

Held earnings shall not be withdrawable until released.

# **15.22 Earning Adjustments**

Authorized administrators may adjust instructor earnings.

Adjustment types:

- Bonus
- Penalty
- Correction
- Reversal
- Settlement correction
- Goodwill adjustment

Rules:

- Adjustment requires reason.
- Adjustment must be audit logged.
- Adjustment must not silently modify original earning.
- Correction should create separate adjustment entry.
- High-value adjustments may require approval in future.

# **15.23 Settlement Eligibility**

Earnings become eligible for settlement after platform-defined conditions are met.

Possible conditions:

- Lesson completed.
- Auto-completion delay passed.
- No dispute raised.
- No technical issue pending.
- Payment received.
- Refund window passed.
- Instructor account active.
- Admin hold not applied.

Recommended Version 1:

- Earnings become eligible after lesson completion plus configurable settlement delay.

Example:

- Lesson completed on Monday.
- Settlement delay: 48 hours.
- Earning becomes eligible on Wednesday if no issue is pending.

# **15.24 Settlement Cycle**

The platform may process settlements on a defined cycle.

Examples:

- Weekly
- Biweekly
- Monthly
- Manual settlement batch

Recommended Version 1:

- Weekly or manual settlement cycle.

The settlement cycle determines when eligible earnings become withdrawable or are processed for payout.

# **15.25 Withdrawable Balance**

Withdrawable balance represents instructor earnings available for withdrawal.

Withdrawable balance is calculated from:

- Eligible settled earnings
- Approved incentives
- Approved adjustments
- Minus withdrawals
- Minus holds
- Minus reversals

Withdrawable balance shall be derived from ledger-like earning records, not manually edited.

# **15.26 Withdrawal Methods**

Instructor withdrawal methods may vary by country.

Version 1 India-focused withdrawal methods may include:

- Bank transfer
- UPI
- Manual payout through admin process

Instructor payout details may include:

- Account holder name
- Bank account number
- IFSC code
- Bank name
- UPI ID, if enabled
- PAN, if required later
- Address, if required later

Sensitive payout details must be protected.

# **15.27 Withdrawal Request**

Instructors may request withdrawal of eligible balance.

Withdrawal request shall include:

- Instructor
- Requested amount
- Currency
- Withdrawal method
- Payout details reference
- Status
- Request timestamp

The system shall validate:

- Minimum withdrawal amount
- Available withdrawable balance
- Active instructor status
- Valid payout method
- Compliance requirements, where enabled

# **15.28 Withdrawal Status Definitions**

## **Requested**

Instructor has submitted withdrawal request.

## **Under Review**

Administrator is reviewing the request.

## **Approved**

Withdrawal has been approved for payment.

## **Processing**

Payment is being processed.

## **Paid**

Instructor payout has been completed.

## **Rejected**

Request was rejected with reason.

## **Failed**

Payout attempt failed.

## **Cancelled**

Withdrawal request was cancelled before processing.

# **15.29 Withdrawal Approval**

Administrators may review withdrawal requests.

Admin review may include:

- Instructor identity verification
- Payout details verification
- Earning history
- Dispute status
- Hold status
- Minimum balance
- Fraud risk
- Manual compliance check

Approved withdrawals reduce withdrawable balance according to financial ledger rules.

# **15.30 Withdrawal Rejection**

Administrators may reject withdrawal requests.

Rejection requires:

- Reason
- Admin identity
- Timestamp
- Notification to instructor
- Audit log

Rejected withdrawal amount becomes available again unless separately held.

# **15.31 Withdrawal Processing**

Version 1 may support manual payout processing.

Manual payout flow:

Instructor requests withdrawal

│

▼

Admin reviews request

│

▼

Admin approves

│

▼

Finance processes bank/UPI transfer

│

▼

Admin marks payout as paid

│

▼

Instructor notified

Future versions may integrate automated payouts.

# **15.32 Instructor Earnings Dashboard**

Instructor dashboard shall display:

- Pending earnings
- Eligible earnings
- Withdrawable balance
- Total lifetime earnings
- Recent lesson earnings
- Incentive earnings
- Withdrawal requests
- Withdrawal history
- Earnings on hold
- Expected next settlement date

The dashboard must be understandable and transparent without exposing platform margin.

# **15.33 Admin Earnings Management**

Administrators shall be able to:

- View instructor earnings
- Filter earnings by status
- Search by instructor
- Search by booking
- View earning calculation snapshot
- Place earnings on hold
- Release holds
- Create adjustments
- Review incentives
- Generate settlement batches
- Review withdrawal requests
- Export reports

All financial admin actions must be permission-controlled and audit logged.

# **15.34 Instructor Settlement Ledger**

The platform should maintain an instructor settlement ledger similar in principle to student wallet ledger.

Every instructor financial movement should be traceable.

Ledger entries may include:

- Lesson earning
- Incentive earning
- Bonus
- Penalty
- Hold
- Hold release
- Reversal
- Withdrawal request
- Withdrawal paid
- Withdrawal rejected
- Admin adjustment

This ledger protects financial accuracy and auditability.

# **15.35 Tax and Compliance**

Tax handling may be introduced later depending on business setup and jurisdiction.

Version 1 may record basic payout and invoice-related data, but advanced tax workflows may be future scope.

Future tax/compliance features may include:

- Instructor PAN collection
- TDS tracking
- GST handling
- Payout statements
- Form generation
- Compliance exports
- Country-specific tax reporting

# **15.36 Functional Requirements**

## **Earning Creation**

### **Lesson Earning Creation**

**Priority:** Critical

The system shall create instructor earnings for eligible completed paid lessons.

### **Recurring Lesson Earning**

**Priority:** Critical

The system shall create instructor earnings per eligible completed recurring lesson occurrence.

### **Demo Incentive Earning**

**Priority:** High

The system shall support demo-related instructor incentives where configured.

### **No Earning for Instructor No-Show**

**Priority:** Critical

The system shall prevent instructor earning generation when the instructor is marked no-show.

### **Student No-Show Compensation**

**Priority:** High

The system shall support instructor compensation for student no-show according to policy.

## **Pay Rules**

### **Instructor Pay Rule Configuration**

**Priority:** Critical

Administrators shall be able to configure instructor pay rules.

### **Instructor-Specific Pay**

**Priority:** High

Administrators shall be able to define instructor-specific pay rates.

### **Subject and Duration-Based Pay**

**Priority:** High

The system shall support pay rates based on subject and lesson duration.

### **Pay Calculation Snapshot**

**Priority:** Critical

The system shall store a calculation snapshot when instructor earning is created.

### **Historical Pay Preservation**

**Priority:** Critical

Future changes to pay rules shall not modify historical earnings.

## **Incentives**

### **Incentive Configuration**

**Priority:** High

Administrators shall be able to configure instructor incentive rules.

### **Demo-to-Paid Incentive**

**Priority:** High

The system shall support instructor incentive for demo-to-paid conversion where configured.

### **Campaign Incentives**

**Priority:** Medium

The system shall support campaign-based instructor incentives.

### **Incentive Eligibility Validation**

**Priority:** High

The system shall validate incentive eligibility before creating incentive earnings.

## **Holds and Adjustments**

### **Earning Hold**

**Priority:** High

Authorized administrators shall be able to place instructor earnings on hold.

### **Hold Release**

**Priority:** High

Authorized administrators shall be able to release held earnings.

### **Earning Adjustment**

**Priority:** High

Authorized administrators shall be able to create earning adjustments with mandatory reason.

### **Earning Reversal**

**Priority:** High

The system shall support earning reversals through separate financial entries.

## **Settlement**

### **Settlement Eligibility**

**Priority:** Critical

The system shall determine when instructor earnings become eligible for settlement.

### **Settlement Delay**

**Priority:** High

The system shall support configurable settlement delay after lesson completion.

### **Settlement Batch**

**Priority:** High

The system shall support grouping eligible earnings into settlement batches.

### **Withdrawable Balance Calculation**

**Priority:** Critical

The system shall calculate withdrawable balance from eligible earnings, adjustments, holds, reversals, and withdrawals.

## **Withdrawals**

### **Withdrawal Method Management**

**Priority:** High

Instructors shall be able to maintain permitted withdrawal methods.

### **Withdrawal Request**

**Priority:** Critical

Instructors shall be able to request withdrawal of eligible withdrawable balance.

### **Minimum Withdrawal Amount**

**Priority:** High

The system shall enforce configured minimum withdrawal amount.

### **Withdrawal Approval**

**Priority:** High

Authorized administrators shall be able to approve withdrawal requests.

### **Withdrawal Rejection**

**Priority:** High

Authorized administrators shall be able to reject withdrawal requests with reason.

### **Withdrawal Paid Marking**

**Priority:** High

Authorized administrators shall be able to mark approved withdrawal requests as paid.

### **Withdrawal History**

**Priority:** Critical

Instructors shall be able to view withdrawal history.

## **Dashboards and Reports**

### **Instructor Earnings Dashboard**

**Priority:** Critical

The system shall provide instructors with an earnings dashboard.

### **Admin Earnings View**

**Priority:** Critical

Administrators shall be able to view instructor earnings and settlements.

### **Earning Export**

**Priority:** Medium

Administrators shall be able to export earning and settlement records where permitted.

### **Settlement Reports**

**Priority:** High

The system shall provide settlement reports for financial review.

## **Audit**

### **Financial Audit Log**

**Priority:** Critical

The system shall audit log all earning adjustments, holds, releases, settlement approvals, and withdrawal actions.

### **Instructor Financial Timeline**

**Priority:** High

The system shall maintain a financial timeline for instructor earning events.

# **15.37 Business Rules**

- Instructor pay shall be separate from student-facing price.
- Student-facing price shall never be shown to instructors as part of earning calculation.
- Instructor earnings shall be created only for eligible completed lessons.
- Instructor no-show lessons shall not generate instructor earnings.
- Student no-show compensation shall follow platform policy.
- Demo lesson compensation shall depend on configured policy.
- Future changes to instructor pay rules shall not alter historical earnings.
- Every earning must reference a booking, incentive, adjustment, or admin action.
- Held earnings shall not be withdrawable.
- Withdrawals shall not exceed withdrawable balance.
- Withdrawal requests require a valid payout method.
- Admin financial adjustments require reason and audit log.
- Withdrawable balance shall not be manually edited directly.
- Instructor earnings are INR-based in Version 1.
- Rejected withdrawal amounts return to available withdrawable balance unless separately held.

# **15.38 Validation Rules**

- Earning amount must be greater than or equal to zero.
- Instructor must be active or eligible for payout according to policy.
- Lesson must be completed before standard lesson earning is created.
- Earning currency must match configured instructor earning currency.
- Withdrawal amount must not exceed withdrawable balance.
- Withdrawal amount must satisfy minimum withdrawal amount.
- Withdrawal method must be valid and approved where required.
- Admin adjustment reason is mandatory.
- Incentive must satisfy configured eligibility rules before earning creation.
- A booking shall not generate duplicate instructor earnings.

# **15.39 User Workflows**

## **Paid Lesson Earning Creation**

- Paid lesson is completed.
- System checks payment and booking status.
- System checks instructor eligibility.
- System applies instructor pay rule.
- System creates earning record.
- System stores calculation snapshot.
- Earning status becomes pending.
- Instructor dashboard updates.

## **Earning Becomes Eligible**

- Settlement delay passes.
- System checks for disputes or holds.
- System verifies lesson remains eligible.
- Earning status changes to eligible.
- Earning becomes available for settlement.

## **Demo-to-Paid Incentive**

- Student completes demo lesson.
- Student books paid lesson within configured conversion window.
- Paid lesson is completed.
- System validates incentive rule.
- Incentive earning is created.
- Instructor dashboard updates.

## **Instructor Withdrawal Request**

- Instructor opens earnings dashboard.
- Instructor views withdrawable balance.
- Instructor enters withdrawal amount.
- Instructor selects payout method.
- System validates amount and method.
- Withdrawal request is created.
- Admin is notified.

## **Admin Approves Withdrawal**

- Admin opens withdrawal request.
- Admin reviews instructor, balance, method, and risks.
- Admin approves request.
- Request status becomes approved or processing.
- Finance processes payout.
- Admin marks withdrawal as paid.
- Instructor is notified.

## **Admin Rejects Withdrawal**

- Admin opens withdrawal request.
- Admin identifies issue.
- Admin enters rejection reason.
- Request is rejected.
- Amount returns to withdrawable balance unless held.
- Instructor is notified.

## **Admin Places Earning on Hold**

- Admin opens instructor earning.
- Admin selects hold action.
- Admin enters reason.
- Earning status becomes on hold.
- Earning is excluded from withdrawal.
- Audit log is recorded.

# **15.40 Exception Handling**

## **Duplicate Earning Attempt**

The system shall prevent duplicate earning creation for the same booking occurrence.

## **Lesson Completed but Payment Missing**

The system shall place earning creation on hold or prevent earning creation until payment is resolved.

## **Technical Issue Reported**

The system may place earning on hold until the issue is reviewed.

## **Instructor No-Show Dispute**

The earning shall remain blocked until admin review is completed.

## **Withdrawal Amount Exceeds Balance**

The system shall reject the withdrawal request and show available balance.

## **Invalid Payout Details**

The system shall block withdrawal request until valid payout details are provided.

## **Admin Marks Payout Incorrectly**

Correction shall be handled through adjustment or reversal entries, not by silently editing historical records.

# **15.41 Notifications**

Instructor earning notifications include:

## **Instructor**

- Lesson earning created
- Incentive earned
- Earning eligible for withdrawal
- Earning placed on hold
- Hold released
- Withdrawal requested
- Withdrawal approved
- Withdrawal rejected
- Withdrawal paid
- Payout details issue

## **Administrator**

- New withdrawal request
- High-value withdrawal request
- Earning hold required
- Technical issue affecting settlement
- Duplicate earning attempt detected
- Payout processing failure
- Reconciliation issue

# **15.42 Reports & Analytics**

Earnings and settlement reports may include:

- Total instructor earnings
- Pending earnings
- Eligible earnings
- Held earnings
- Settled earnings
- Withdrawal requested amount
- Withdrawal paid amount
- Instructor-wise earnings
- Subject-wise instructor earnings
- Lesson-duration-wise earnings
- Incentive payouts
- Demo conversion incentives
- Manual adjustments
- Reversed earnings
- Settlement batch reports
- Country-wise instructor payouts, future
- INR payout summary

# **15.43 Administrative Configuration**

Administrators shall be able to configure:

- Instructor pay models
- Instructor-specific rates
- Subject-based rates
- Duration-based rates
- Instructor tiers
- Demo compensation policy
- Demo-to-paid incentive rules
- Performance incentive rules
- Settlement delay
- Settlement cycle
- Minimum withdrawal amount
- Permitted withdrawal methods
- Withdrawal approval permissions
- Manual adjustment permissions
- Financial notification templates
- Earnings export permissions

# **15.44 Acceptance Criteria**

- Instructor earnings are created only for eligible completed paid lessons.
- Instructor compensation remains separate from student-facing price.
- Instructors can view earnings without seeing platform margin or student pricing strategy.
- Demo incentives can be configured without changing booking logic.
- Historical earnings remain unchanged after pay rule updates.
- Held earnings are excluded from withdrawable balance.
- Instructors can request withdrawals only up to available withdrawable balance.
- Administrators can approve, reject, process, and audit withdrawal requests.
- Every earning, adjustment, hold, settlement, and withdrawal has a traceable financial record.
- Settlement and withdrawal reports are available for financial review.

# **15.45 Future Enhancements**

This module is designed to support future expansion, including:

- Automated RazorpayX payouts
- Multi-currency instructor payouts
- Instructor tier automation
- Performance-based dynamic pay
- AI-assisted instructor quality scoring
- Tax deduction tracking
- GST/TDS workflows
- Instructor payout statements
- Instructor bonus campaigns
- Minimum guaranteed earnings
- Package-based instructor settlement
- Subscription-based instructor settlement
- Group class instructor revenue sharing
- Corporate training payouts
- Instructor financial risk scoring
- Automated payout reconciliation
- Accounting software integration

# **15.46 Chapter Summary**

The Instructor Earnings, Incentives, Settlement & Withdrawals module defines the internal financial relationship between STEM Learning and its instructors.

The chapter establishes a critical business rule: instructor earnings are separate from student-facing price. This protects platform margin, allows regional student pricing, and enables flexible instructor compensation.

By using configurable pay rules, earning snapshots, settlement delays, holds, adjustments, and withdrawal workflows, the platform can manage instructor payments accurately, transparently, and securely.

This module completes the core money flow from student payment to instructor payout while preserving financial traceability and operational control.

# **CHAPTER 16 - STUDENT REFERRAL, REWARDS & PROMOTIONAL CREDIT SYSTEM**

# **16.1 Introduction**

The Student Referral, Rewards & Promotional Credit System governs how students invite new users, how referral eligibility is determined, how rewards are calculated, how promotional credits are issued, and how all reward-related credits are tracked within the STEM Learning platform.

For Version 1, the referral program shall be student-focused. Students may refer other students, and eligible rewards shall be credited to the student wallet according to active campaign rules.

This module is closely integrated with Student Management, Wallet Management, Booking, Payments, Learning Activity, Analytics, Fraud Prevention, and Notifications.

The referral and reward system must support growth while preventing misuse, duplicate rewards, fake accounts, self-referrals, and reward abuse.

# **16.2 Purpose**

The purpose of this module is to provide a controlled referral and rewards engine that supports student acquisition, retention, and repeat learning.

The module must ensure:

- Students can invite others using referral codes or links.
- Referral eligibility is validated before reward issuance.
- Rewards are credited to wallet only after qualifying conditions are met.
- Reward campaigns are configurable by administrators.
- Referral credits are traceable to specific students, bookings, and campaigns.
- Fraud and abuse risks are minimized.
- Promotional credits can be issued separately from referral rewards.

# **16.3 Business Objectives**

This module shall support the following business objectives:

- Encourage student-led growth.
- Reduce customer acquisition cost.
- Reward genuine student referrals.
- Increase paid lesson conversion.
- Encourage repeat bookings.
- Support configurable referral campaigns.
- Credit rewards through wallet instead of cash payout.
- Prevent referral abuse.
- Support country-specific promotional campaigns.
- Provide analytics for campaign performance.

# **16.4 Scope**

This chapter covers:

## **Referral Program**

- Student referral code
- Referral link
- Referred student registration
- Referral attribution
- Referral eligibility
- Referral reward calculation
- Referral reward wallet credit

## **Rewards**

- Percentage-based reward
- Fixed reward
- Per-class reward
- Maximum rewarded lessons
- Campaign rules
- Reward status

## **Promotional Credits**

- Admin-issued credits
- Campaign credits
- Launch credits
- Goodwill credits
- Country-specific credits
- Subject-specific credits, future

## **Administration**

- Campaign creation
- Campaign configuration
- Campaign activation
- Referral review
- Fraud detection
- Reward approval
- Reward reports

# **16.5 Referral Philosophy**

The referral system should reward genuine student growth, not artificial registrations.

The platform shall not issue rewards only because someone signs up. Rewards should be linked to meaningful business value such as paid lesson completion.

The recommended principle is:

Referral reward is earned only after real paid learning activity.

This ensures that rewards are tied to revenue and student engagement.

# **16.6 Version 1 Referral Model**

Version 1 referral model:

- Student refers another student.
- Referred student registers using referral link or code.
- Referred student books and completes at least one paid class.
- Referrer becomes eligible for wallet reward.
- Reward may continue per eligible class up to campaign limit.
- Reward is credited to referrer wallet.
- Reward is not paid as cash.

Your finalized business rule:

Student referral only

Reward after paid class completion

Per-class reward support

Maximum 10 rewarded classes

Suggested reward: 5% per eligible class

Minimum eligibility: 1 paid class

Wallet credit only

The exact values remain administrator-configurable.

# **16.7 Referral Participants**

## **Referrer**

The existing student who invites another student.

## **Referred Student**

The new student who registers through referral link or code.

## **Campaign**

The active referral configuration that controls eligibility, reward value, limits, duration, and country applicability.

## **Reward Event**

The event that creates referral reward eligibility.

Example:

- Referred student completes first paid lesson.
- Referred student completes second eligible paid lesson.
- Referred student completes up to tenth eligible paid lesson.

# **16.8 Referral Code**

Each student shall receive a unique referral code.

Referral code rules:

- Unique across platform.
- Assigned to student.
- Can be shared publicly by the student.
- Can be disabled if abuse is detected.
- Remains linked to student account.
- Should be easy to copy and share.

Example format:

NILESH25

STEM1234

STU8X9Z

The exact format is configurable.

# **16.9 Referral Link**

The platform shall generate a referral link using the student's referral code.

Example:

<https://stemlearning.com/register?ref=NILESH25>

The referral link should support:

- Registration attribution
- Marketing sharing
- WhatsApp sharing
- Email sharing
- Social sharing
- Campaign tracking, future

# **16.10 Referral Attribution**

Referral attribution determines which referrer gets credit.

Rules:

- Attribution is recorded when referred student registers with a valid referral code or link.
- One referred student can have only one referrer.
- Referral attribution cannot be changed by the referred student after registration.
- Admin may correct attribution only with permission and audit reason.
- Self-referral is not permitted.
- Duplicate referral relationships are not permitted.

# **16.11 Referral Eligibility**

A referral becomes reward-eligible only when configured conditions are satisfied.

Possible eligibility conditions:

- Referred student account created.
- Referred student email verified.
- Referred student completes first paid lesson.
- Referred student completes minimum number of paid lessons.
- Payment is not refunded.
- Booking is not cancelled.
- Instructor no-show did not invalidate the lesson.
- Fraud review is clear.
- Campaign is active.
- Referral is within campaign validity period.

Recommended Version 1 minimum eligibility:

Referred student must complete at least 1 paid lesson.

# **16.12 Referral Reward Calculation**

Referral rewards may be calculated using one of the following models.

## **Percentage-Based Reward**

Example:

- Referrer earns 5% of eligible lesson amount.

## **Fixed Reward**

Example:

- Referrer earns ₹100 after referred student completes first paid lesson.

## **Per-Class Reward**

Example:

- Referrer earns 5% for each eligible completed paid class, up to 10 classes.

## **Hybrid Reward**

Example:

- Fixed reward after first paid class plus smaller per-class reward.

Recommended Version 1:

- Per-class reward support.
- Configurable percentage.
- Configurable maximum rewarded classes.
- Wallet credit only.

# **16.13 Maximum Reward Limit**

The platform shall support maximum reward limits.

Examples:

- Maximum 10 classes per referred student.
- Maximum ₹X reward per referred student.
- Maximum ₹X reward per referrer per month.
- Maximum campaign budget.

Your current recommended rule:

Maximum 10 rewarded classes per referred student.

This prevents unlimited reward liability.

# **16.14 Reward Timing**

Reward timing should be configurable.

Examples:

- Immediately after eligible paid lesson completion.
- After refund window expires.
- After settlement delay.
- After admin review.
- After campaign validation.

Recommended Version 1:

- Reward after paid lesson completion and refund-risk window, if configured.
- If no refund window is configured, reward after eligible lesson completion.

# **16.15 Reward Status Definitions**

Referral rewards may have the following statuses.

## **Pending**

Reward condition has started but is not fully satisfied.

## **Eligible**

Reward qualifies based on campaign rules.

## **Approved**

Reward is approved for wallet credit.

## **Credited**

Reward has been credited to wallet.

## **Held**

Reward is under review.

## **Rejected**

Reward failed eligibility or fraud checks.

## **Reversed**

Reward was corrected or cancelled after issuance.

# **16.16 Wallet Credit**

Referral rewards shall be credited to the student wallet.

Rules:

- Reward is credited to referrer wallet.
- Reward currency follows referrer wallet currency.
- Reward credit creates wallet ledger transaction.
- Reward credit links to referral campaign and reward event.
- Reward is not paid as cash.
- Reward cannot create negative wallet balance.
- Reversal requires separate wallet transaction.

# **16.17 Promotional Credits**

Promotional credits are wallet credits issued outside referral flow.

Examples:

- Launch offer
- Festival campaign
- Student retention credit
- Admin goodwill credit
- Country-specific promotion
- Subject-specific promotion, future
- First lesson bonus
- Reactivation bonus

Promotional credits must be controlled by administrators and recorded in wallet ledger.

# **16.18 Promotional Credit Types**

Promotional credits may include:

## **Fixed Credit**

Example:

- ₹500 wallet credit.

## **Percentage Cashback**

Example:

- 10% cashback after eligible booking.

## **First Booking Credit**

Example:

- Credit after first paid lesson.

## **Campaign Credit**

Example:

- Country-specific or subject-specific campaign.

## **Manual Goodwill Credit**

Example:

- Admin credits wallet due to support issue.

# **16.19 Promotional Credit Restrictions**

Version 1 may keep promotional credits simple.

Future restrictions may include:

- Expiry date
- Minimum booking amount
- Subject applicability
- Country applicability
- New students only
- One-time use
- Non-refundable
- Non-transferable
- Cannot be withdrawn
- Cannot be combined with other offers

For Version 1, promotional credits may be treated as wallet credits unless advanced restrictions are enabled.

# **16.20 Fraud and Abuse Prevention**

The referral system must include abuse prevention.

Fraud checks may include:

- Self-referral prevention
- Same email prevention
- Same phone prevention
- Duplicate account detection
- Repeated payment/refund patterns
- Suspicious referral clusters
- Same device/IP pattern, future
- Referred account cancellation abuse
- Repeated no-show/refund abuse

Suspicious referrals may be held for admin review.

# **16.21 Self-Referral Prevention**

Self-referral is not allowed.

The system should prevent referral where:

- Referrer and referred student are the same account.
- Email matches.
- Phone number matches.
- Payment identity matches, future.
- Device/IP risk score indicates likely abuse, future.

# **16.22 Referral Campaign Management**

Administrators shall be able to create referral campaigns.

Campaign configuration may include:

- Campaign name
- Description
- Start date
- End date
- Status
- Eligible countries
- Reward type
- Reward value
- Maximum rewarded classes
- Minimum paid lessons
- Reward timing
- Fraud review requirement
- Budget cap, future
- Terms and conditions

Only active campaigns shall generate new referral rewards.

# **16.23 Campaign Status Definitions**

Referral campaigns may have statuses:

## **Draft**

Campaign is being configured.

## **Active**

Campaign is live and can generate rewards.

## **Paused**

Campaign is temporarily stopped.

## **Completed**

Campaign has ended.

## **Archived**

Campaign is no longer active but preserved for reporting.

# **16.24 Referral Dashboard - Student**

Students should see:

- Referral code
- Referral link
- Share buttons
- Referral program explanation
- Invited students count
- Eligible rewards
- Pending rewards
- Credited rewards
- Reward history
- Campaign terms

The dashboard should be simple and transparent.

# **16.25 Admin Referral Management**

Administrators should be able to:

- View referral relationships
- Search by referrer
- Search by referred student
- View campaign performance
- View pending rewards
- Approve or reject held rewards
- Detect suspicious activity
- Export referral reports
- Disable abusive referral codes
- Adjust referral attribution with audit reason

# **16.26 Referral Analytics**

Referral analytics may include:

- Total referral signups
- Verified referred students
- Referred students who booked paid lessons
- Referral conversion rate
- Reward amount credited
- Pending rewards
- Rejected rewards
- Top referrers
- Campaign performance
- Country-wise referral activity
- Referral fraud flags
- Referral-generated revenue

# **16.27 Notification Strategy**

The system should notify students when:

- Referral link is used.
- Referred student registers.
- Referred student completes eligible paid class.
- Reward becomes eligible.
- Reward is credited.
- Reward is held or rejected.
- Campaign is ending soon, future.

Notifications should avoid exposing private details of the referred student beyond what policy allows.

# **16.28 Functional Requirements**

## **Referral Code & Link**

### **Referral Code Generation**

**Priority:** Critical

The system shall generate a unique referral code for every student.

### **Referral Link Generation**

**Priority:** Critical

The system shall generate a shareable referral link using the student's referral code.

### **Referral Code Visibility**

**Priority:** High

Students shall be able to view and copy their referral code and referral link.

### **Referral Code Disable**

**Priority:** High

Authorized administrators shall be able to disable a referral code if abuse is detected.

## **Referral Attribution**

### **Referral Attribution on Registration**

**Priority:** Critical

The system shall record referral attribution when a new student registers using a valid referral code or link.

### **Single Referrer Rule**

**Priority:** Critical

The system shall allow only one referrer per referred student.

### **Self-Referral Prevention**

**Priority:** Critical

The system shall prevent students from referring themselves.

### **Admin Attribution Correction**

**Priority:** Medium

Authorized administrators may correct referral attribution with mandatory reason and audit log.

## **Eligibility**

### **Referral Eligibility Evaluation**

**Priority:** Critical

The system shall evaluate referral reward eligibility based on active campaign rules.

### **Paid Lesson Completion Requirement**

**Priority:** Critical

The system shall support paid lesson completion as a reward eligibility condition.

### **Minimum Paid Lesson Requirement**

**Priority:** High

The system shall support a configurable minimum number of completed paid lessons before reward eligibility.

### **Refund and Cancellation Check**

**Priority:** High

The system shall prevent reward issuance for cancelled, refunded, or invalid lessons according to campaign policy.

## **Reward Calculation**

### **Percentage Reward**

**Priority:** High

The system shall support percentage-based referral reward calculation.

### **Fixed Reward**

**Priority:** Medium

The system shall support fixed referral reward calculation.

### **Per-Class Reward**

**Priority:** Critical

The system shall support per-class referral rewards up to a configured limit.

### **Maximum Rewarded Classes**

**Priority:** Critical

The system shall support maximum rewarded classes per referred student.

### **Reward Calculation Snapshot**

**Priority:** High

The system shall store reward calculation snapshot at the time of reward creation.

## **Wallet Credit**

### **Wallet Reward Credit**

**Priority:** Critical

The system shall credit approved referral rewards to the referrer's wallet.

### **Wallet Ledger Linkage**

**Priority:** Critical

Referral reward credits shall create wallet ledger transactions linked to referral campaign and reward event.

### **Reward Reversal**

**Priority:** High

The system shall support referral reward reversal through separate wallet ledger transaction.

## **Promotional Credits**

### **Promotional Credit Campaign**

**Priority:** High

Administrators shall be able to create promotional credit campaigns.

### **Manual Promotional Credit**

**Priority:** High

Authorized administrators shall be able to issue manual promotional wallet credits with reason.

### **Promotional Credit Ledger**

**Priority:** Critical

Every promotional credit shall create a wallet ledger transaction.

### **Promotional Credit Restrictions**

**Priority:** Medium

The system shall support configurable promotional credit restrictions in future-ready design.

## **Campaign Management**

### **Referral Campaign Creation**

**Priority:** Critical

Administrators shall be able to create referral campaigns.

### **Campaign Activation**

**Priority:** Critical

Administrators shall be able to activate, pause, complete, and archive referral campaigns.

### **Campaign Country Eligibility**

**Priority:** Medium

The system shall support country-specific campaign eligibility.

### **Campaign Reward Limits**

**Priority:** Critical

The system shall enforce configured campaign reward limits.

## **Fraud Review**

### **Fraud Flagging**

**Priority:** High

The system shall flag suspicious referral activity for admin review.

### **Reward Hold**

**Priority:** High

The system shall support placing referral rewards on hold.

### **Reward Approval**

**Priority:** High

Authorized administrators shall be able to approve held referral rewards.

### **Reward Rejection**

**Priority:** High

Authorized administrators shall be able to reject referral rewards with reason.

## **Dashboards & Reports**

### **Student Referral Dashboard**

**Priority:** High

Students shall be able to view referral code, link, invited students, pending rewards, and credited rewards.

### **Admin Referral Dashboard**

**Priority:** High

Administrators shall be able to view referral relationships, campaign performance, and reward status.

### **Referral Export**

**Priority:** Medium

Administrators shall be able to export referral and reward reports where permitted.

## **Notifications**

### **Referral Notification**

**Priority:** Medium

The system shall notify students about important referral and reward events.

# **16.29 Business Rules**

- Version 1 referral program shall be student-only.
- Referral rewards shall be credited to wallet only.
- Referral rewards shall not be paid as cash in Version 1.
- A referred student can have only one referrer.
- Self-referral is not permitted.
- Referral rewards are issued only after campaign eligibility conditions are met.
- Recommended Version 1 reward eligibility requires at least one completed paid lesson.
- Per-class rewards shall not exceed the configured maximum rewarded classes.
- Recommended maximum is 10 rewarded classes per referred student.
- Suggested reward is 5% per eligible class, but final value is administrator-configurable.
- Cancelled, refunded, or invalid lessons shall not generate referral rewards unless explicitly allowed by policy.
- Referral credits must create wallet ledger entries.
- Promotional credits must create wallet ledger entries.
- Suspicious referral activity may hold rewards for review.
- Admin referral changes require reason and audit log.
- Archived campaigns shall not generate new rewards.

# **16.30 Validation Rules**

- Referral code must be unique.
- Referral code must belong to an active student unless disabled by policy.
- A student cannot use their own referral code.
- A referred student cannot be assigned multiple referrers.
- Referral campaign must be active at the time eligibility is evaluated.
- Reward amount must be greater than or equal to zero.
- Reward currency must match referrer wallet currency.
- Maximum rewarded classes limit must not be exceeded.
- Manual promotional credit requires administrator reason.
- Held reward cannot be credited until approved.

# **16.31 User Workflows**

## **Student Shares Referral Link**

- Student opens referral dashboard.
- Student copies referral code or referral link.
- Student shares link with another person.
- New student opens referral link.
- New student registers.
- System records referral attribution.

## **Referral Reward After Paid Lesson**

- Referred student completes registration.
- Referred student books paid lesson.
- Paid lesson is completed.
- System checks campaign eligibility.
- System calculates reward.
- Reward becomes eligible or pending.
- Reward is credited to referrer wallet after approval/timing rules.
- Wallet ledger transaction is created.
- Referrer receives notification.

## **Per-Class Referral Reward**

- Referred student completes paid class.
- System counts eligible rewarded classes.
- If class count is within maximum limit, reward is calculated.
- Reward is credited according to campaign timing.
- If limit is exceeded, no further reward is generated.

## **Admin Creates Referral Campaign**

- Admin opens referral campaign management.
- Admin creates campaign.
- Admin defines reward type and value.
- Admin defines eligibility rules.
- Admin defines maximum rewarded classes.
- Admin defines campaign dates.
- Admin activates campaign.
- System begins applying campaign rules.

## **Admin Reviews Suspicious Reward**

- System flags suspicious referral reward.
- Reward status becomes held.
- Admin opens fraud review.
- Admin reviews referral, student, booking, payment, and wallet activity.
- Admin approves or rejects reward.
- Action is audit logged.
- Student is notified where appropriate.

## **Admin Issues Promotional Credit**

- Admin opens student wallet or campaign panel.
- Admin selects promotional credit.
- Admin enters amount and reason.
- System validates permissions.
- Wallet is credited.
- Ledger transaction is created.
- Audit log is recorded.
- Student receives notification.

# **16.32 Exception Handling**

## **Invalid Referral Code**

The system shall allow registration to continue but shall not create referral attribution.

## **Referral Code Disabled**

The system shall reject attribution and show a generic message without exposing abuse details.

## **Self-Referral Detected**

The system shall block referral attribution and may flag the account for review.

## **Duplicate Referral Attempt**

The system shall preserve the original referral attribution and ignore later attempts.

## **Reward Calculation Error**

The reward shall remain pending, and administrators shall be notified.

## **Wallet Credit Failed**

The system shall retry wallet credit and notify administrators if unresolved.

## **Fraud Suspected**

The reward shall be placed on hold until admin review.

## **Campaign Expired**

No new referral rewards shall be generated after campaign expiry unless policy allows grace handling for already-attributed users.

# **16.33 Notifications**

Referral and reward notifications may include:

## **Student**

- Referral link copied/shared, optional
- New referred student registered
- Referral reward pending
- Referral reward credited
- Referral reward held for review
- Referral reward rejected
- Promotional credit received
- Campaign ending soon, future

## **Administrator**

- Suspicious referral activity
- Reward credit failure
- High-value referral reward
- Campaign budget threshold, future
- Top referrer alert
- Referral fraud cluster detected, future

# **16.34 Reports & Analytics**

Referral reports may include:

- Total referral codes generated
- Referral link usage
- Referral registrations
- Verified referred students
- Referred paid students
- Referral conversion rate
- Reward amount credited
- Pending reward amount
- Held reward amount
- Rejected reward amount
- Top referrers
- Campaign-wise performance
- Country-wise referral activity
- Subject-wise referral conversion
- Referral-generated revenue
- Cost per referred paid student
- Abuse flags

# **16.35 Administrative Configuration**

Administrators shall be able to configure:

- Referral system enabled/disabled
- Referral code format
- Referral campaign rules
- Reward type
- Reward value
- Maximum rewarded classes
- Minimum paid lesson requirement
- Reward timing
- Reward approval requirement
- Country eligibility
- Fraud review rules
- Promotional credit permissions
- Promotional credit limits
- Referral notification templates
- Referral report permissions

# **16.36 Acceptance Criteria**

- Every student receives a unique referral code and shareable referral link.
- A new student can be attributed to one referrer during registration.
- Self-referrals and duplicate referrer assignments are prevented.
- Referral rewards are generated only after configured eligibility conditions are met.
- The system supports per-class rewards up to a configured maximum limit.
- Referral and promotional rewards are credited to wallet through ledger transactions.
- Administrators can configure referral campaigns, reward rules, and promotional credits.
- Suspicious referral rewards can be held, reviewed, approved, or rejected.
- Students can view referral status, pending rewards, and credited rewards.
- Administrators can report on referral performance, cost, and abuse indicators.

# **16.37 Future Enhancements**

This module is designed to support future expansion, including:

- Instructor referral program
- Parent referral program
- Affiliate partner program
- Influencer referral campaigns
- Coupon codes
- Gift cards
- Cashback wallets
- Bonus wallet and cash wallet separation
- Expiring promotional credits
- Reward budget limits
- Referral fraud scoring
- Device/IP-based abuse detection
- Multi-level referral restrictions
- Social sharing analytics
- Campaign A/B testing
- Automated campaign optimization
- Country-specific referral programs
- Corporate referral campaigns

# **16.38 Chapter Summary**

The Student Referral, Rewards & Promotional Credit System provides a controlled growth engine for STEM Learning.

For Version 1, the referral program is student-only, wallet-based, and tied to real paid learning activity. This ensures that rewards support genuine business value rather than artificial signups.

The module supports per-class rewards, configurable campaign rules, maximum reward limits, wallet ledger integration, promotional credits, fraud review, and campaign analytics.

By keeping rewards configurable and traceable, STEM Learning can safely use referrals and promotions to grow while protecting financial integrity and reducing abuse risk.

PART E

# **PART E - ENGAGEMENT**

# **CHAPTER 17 - NOTIFICATIONS, COMMUNICATION & MESSAGING SYSTEM**

# **17.1 Introduction**

The Notifications, Communication & Messaging System governs how STEM Learning communicates with students, instructors, administrators, and future support users.

This module is responsible for sending timely, relevant, and secure communication across important platform events such as registration, email verification, booking confirmation, payment success, wallet recharge, lesson reminders, meeting links, homework assignment, review requests, referral rewards, withdrawal updates, and administrative alerts.

The communication system is also responsible for controlled student-instructor messaging. Since STEM Learning is a managed learning marketplace, direct communication must support learning while reducing off-platform leakage risk.

This module integrates with Authentication, Student Management, Instructor Management, Booking, Availability, Meeting Management, Wallet, Payments, Homework, Reviews, Referrals, Admin Operations, and future AI services.

# **17.2 Purpose**

The purpose of this module is to ensure that all users receive the right communication at the right time through the right channel.

The module must support:

- System notifications
- Transactional emails
- SMS notifications
- WhatsApp notifications
- In-app notifications
- Controlled student-instructor messaging
- Admin alerts
- Notification preferences
- Notification templates
- Delivery logs
- Failed delivery handling
- Communication auditability

# **17.3 Business Objectives**

The Notifications, Communication & Messaging System shall support the following business objectives:

- Improve student engagement.
- Reduce missed lessons.
- Improve booking conversion.
- Improve recurring lesson continuity.
- Support payment and wallet transparency.
- Reduce support workload.
- Improve instructor responsiveness.
- Protect platform-controlled communication.
- Maintain communication history.
- Prepare the platform for future mobile push and AI-assisted communication.

# **17.4 Scope**

This chapter covers:

## **Notifications**

- Email notifications
- SMS notifications
- WhatsApp notifications
- In-app notifications
- Admin alerts
- System reminders

## **Communication Events**

- Account events
- Booking events
- Payment events
- Wallet events
- Lesson events
- Meeting events
- Homework events
- Review events
- Referral events
- Instructor earning events
- Withdrawal events

## **Messaging**

- Student-instructor chat
- Booking-based messaging
- Learning-plan-based messaging
- Admin-monitored communication
- Messaging restrictions

## **Administration**

- Notification templates
- Notification settings
- Delivery logs
- Retry handling
- Communication reports
- Abuse review

# **17.5 Communication Philosophy**

Communication should be:

- Timely
- Clear
- Actionable
- Respectful
- Secure
- Traceable
- Localized where applicable
- Aligned with platform policies

The system should avoid unnecessary notification noise while ensuring that critical events are never missed.

# **17.6 Communication Channels**

The platform may support the following channels.

## **Email**

Used for formal and transactional communication.

Examples:

- Account verification
- Booking confirmation
- Payment receipt
- Lesson reminder
- Invoice
- Policy notice

## **SMS**

Used for urgent or short notifications.

Examples:

- Lesson reminder
- OTP, if enabled
- Payment alert
- Critical booking update

## **WhatsApp**

Used for high-engagement reminders and updates.

Examples:

- Booking confirmation
- Lesson reminder
- Wallet low-balance reminder
- Homework reminder
- Referral reward notification

## **In-App Notification**

Used inside the student, instructor, and admin dashboards.

Examples:

- New booking
- Homework assigned
- Review requested
- Wallet credited
- Withdrawal approved

## **Future Push Notification**

Future mobile apps may support push notifications.

# **17.7 Notification Priority**

Notifications should be categorized by priority.

## **Critical**

Requires immediate user awareness.

Examples:

- Booking confirmed
- Lesson starting soon
- Payment failed
- Instructor no-show
- Wallet balance insufficient
- Meeting creation failure

## **High**

Important but not emergency.

Examples:

- Homework assigned
- Review request
- Withdrawal approved
- Referral reward credited

## **Normal**

Useful updates.

Examples:

- Profile updated
- Favorite instructor available
- New resource shared

## **Low**

Marketing or optional engagement.

Examples:

- Promotional campaign
- Learning tips
- New subject announcement

# **17.8 Notification Types**

The system shall support structured notification types.

Examples:

- Account
- Security
- Booking
- Availability
- Meeting
- Wallet
- Payment
- Invoice
- Homework
- Learning Plan
- Review
- Referral
- Instructor Earnings
- Withdrawal
- Admin Alert
- Marketing
- Support

Each notification type may have channel, template, priority, and delivery behavior.

# **17.9 Account Notifications**

Account-related notifications include:

- Registration confirmation
- Email verification
- Verification reminder
- Password reset
- Password changed
- Login alert, future
- Account suspended
- Account restored
- Profile approval
- Instructor application submitted
- Instructor application approved
- Instructor application rejected
- Documents required

# **17.10 Booking Notifications**

Booking notifications include:

- Demo booking confirmed
- Paid booking confirmed
- Recurring booking created
- Booking reminder
- Booking cancelled
- Booking rescheduled
- Booking payment pending
- Booking payment failed
- Booking completed
- Student no-show
- Instructor no-show
- Technical issue update

# **17.11 Lesson Reminder Notifications**

The system shall support configurable lesson reminders.

Examples:

- 24 hours before lesson
- 3 hours before lesson
- 1 hour before lesson
- 15 minutes before lesson

Reminder timing should be configurable globally and may later be customized by user preference.

Lesson reminders should include:

- Lesson date
- Lesson time in user timezone
- Subject
- Instructor or student name
- Join lesson action
- Reschedule/cancel policy note, where applicable

# **17.12 Meeting Notifications**

Meeting notifications include:

- Meeting link available
- Meeting starting soon
- Meeting rescheduled
- Meeting cancelled
- Meeting access issue
- Recording available, where enabled
- Technical issue reported
- Admin observer joined, where policy requires notice

Meeting links shall only be shared according to access policy.

# **17.13 Wallet & Payment Notifications**

Wallet and payment notifications include:

- Wallet recharge successful
- Wallet recharge failed
- Wallet debited for booking
- Recurring lesson deduction successful
- Low wallet balance
- Insufficient wallet balance
- Refund credited
- Payment successful
- Payment failed
- Invoice available
- Payment reconciliation issue, admin only

# **17.14 Homework & Learning Activity Notifications**

Homework notifications include:

- Homework assigned
- Homework due soon
- Homework overdue
- Homework submitted
- Homework reviewed
- Instructor feedback added
- New resource shared

Homework reminders should help students maintain learning consistency.

# **17.15 Learning Plan Notifications**

Learning Plan notifications include:

- Learning goal created
- Learning plan activated
- Milestone achieved
- Progress review due
- Plan updated
- Plan completed
- Instructor added note
- Review meeting recommended

These notifications encourage structured academic progress beyond individual lesson booking.

# **17.16 Review Notifications**

Review notifications include:

- Student review request after completed lesson
- Instructor review request, if two-way review is enabled
- Review submitted
- Review approved
- Review rejected or hidden after moderation
- Response to review, future

# **17.17 Referral & Reward Notifications**

Referral notifications include:

- Referral link used
- Referred student registered
- Referral reward pending
- Referral reward credited
- Referral reward held
- Referral reward rejected
- Promotional credit received

Referral communication should avoid exposing unnecessary private information about referred students.

# **17.18 Instructor Earnings & Withdrawal Notifications**

Instructor financial notifications include:

- Lesson earning created
- Incentive earned
- Earning eligible for withdrawal
- Earning placed on hold
- Hold released
- Withdrawal requested
- Withdrawal approved
- Withdrawal rejected
- Withdrawal paid
- Payout details issue

# **17.19 Admin Alerts**

Admin alerts may include:

- Meeting creation failure
- Payment verification failure
- Wallet credit failure
- Instructor no-show
- Student dispute
- Technical issue reported
- Suspicious referral activity
- High refund activity
- Withdrawal request
- Instructor document pending
- Support escalation
- Repeated booking cancellation pattern

Admin alerts should be role-based.

# **17.20 Notification Templates**

Administrators shall be able to manage notification templates.

Templates may include:

- Subject line
- Message body
- Channel
- Language
- Variables
- Status
- Preview
- Test send
- Version history, future

Template variables may include:

- Student name
- Instructor name
- Lesson date
- Lesson time
- Subject
- Booking reference
- Wallet amount
- Payment reference
- Meeting join action
- Referral reward amount

Templates should not expose confidential internal data.

# **17.21 Notification Localization**

Notifications should support localization where applicable.

Localization may include:

- User language
- Country-specific terminology
- Currency formatting
- Date and time format
- Timezone display
- Localized legal footer

Version 1 may begin with English-only templates while preserving future localization readiness.

# **17.22 Notification Preferences**

Users should be able to control certain notification preferences.

Examples:

- Email reminders
- WhatsApp reminders
- SMS reminders
- Marketing notifications
- Learning tips
- Promotional updates

Critical transactional notifications may not be fully disabled.

Examples of non-disableable notifications:

- Payment confirmation
- Booking confirmation
- Password reset
- Security alerts
- Lesson cancellation
- Wallet debit
- Policy-required notices

# **17.23 In-App Notification Center**

Students and instructors should have an in-app notification center.

The notification center should support:

- Unread count
- Notification list
- Read/unread status
- Notification category
- Action button
- Timestamp
- Archive or clear action
- Filtering, future

Examples of action buttons:

- Join Lesson
- View Booking
- Submit Homework
- Recharge Wallet
- Leave Review
- View Earnings

# **17.24 Delivery Logging**

Every notification delivery attempt should be logged.

Delivery log may include:

- Notification type
- Recipient
- Channel
- Template
- Status
- Sent timestamp
- Provider response
- Failure reason
- Retry count
- Related entity reference
- Trigger source

Delivery logs support debugging, compliance, and support resolution.

# **17.25 Delivery Status Definitions**

Notification delivery statuses may include:

## **Pending**

Notification is queued but not yet sent.

## **Sent**

Notification has been sent to provider.

## **Delivered**

Provider confirms delivery, where supported.

## **Failed**

Notification failed.

## **Retrying**

Notification is scheduled for retry.

## **Cancelled**

Notification was cancelled before sending.

## **Suppressed**

Notification was not sent due to user preference, policy, or duplicate protection.

# **17.26 Retry Handling**

The system shall support retry handling for failed notifications.

Retry rules may depend on:

- Notification priority
- Channel
- Provider error
- Time sensitivity
- User preference
- Duplicate protection

Critical notifications should have stronger retry policies.

Expired reminders should not be sent late if no longer useful.

# **17.27 Duplicate Notification Prevention**

The system should prevent duplicate notifications caused by:

- Repeated events
- Queue retries
- Webhook duplicates
- Manual re-trigger
- Browser refresh
- Job failure and retry

Duplicate prevention should use event references and idempotency where applicable.

# **17.28 Controlled Messaging Philosophy**

Student-instructor messaging should exist to support learning, not to replace the platform relationship.

Messaging must be controlled, contextual, and policy-governed.

The platform should prevent open, unrestricted communication before a paid relationship exists.

Recommended Version 1 rule:

Student-instructor chat is enabled only after confirmed paid booking or active learning relationship.

Demo-only messaging may be restricted or limited.

# **17.29 Messaging Eligibility**

Student-instructor messaging may be enabled when:

- Student has a confirmed paid booking with the instructor.
- Student has an active Learning Plan with the instructor.
- Student has an upcoming lesson with the instructor.
- Student has completed lessons with the instructor and communication remains within allowed window.

Messaging may be disabled when:

- No confirmed relationship exists.
- Booking is cancelled.
- Instructor is suspended.
- Student is suspended.
- Abuse is detected.
- Admin disables communication.

# **17.30 Messaging Context**

Messages should be tied to a context.

Examples:

- Booking
- Learning Plan
- Homework
- Lesson
- Support case, future

Contextual messaging helps reduce confusion and supports moderation.

# **17.31 Messaging Features**

Version 1 messaging may support:

- Text messages
- Message read status
- File attachment restrictions, optional
- Homework/resource links
- Booking context
- Admin visibility where policy permits
- Abuse reporting
- Message history

Version 1 should avoid unrestricted file sharing unless needed.

# **17.32 Messaging Restrictions**

Messaging restrictions may include:

- No phone numbers
- No email addresses
- No external meeting links
- No payment solicitation
- No off-platform lesson solicitation
- No abusive language
- No inappropriate content
- No spam
- No sharing personal contact details

The system may use automated detection later, but Version 1 may rely on policy, reporting, and admin review.

# **17.33 Communication Leakage Prevention**

The communication system should reduce off-platform leakage.

Measures include:

- Platform-only messaging
- Controlled availability of chat
- No pre-booking unrestricted chat
- Prohibited contact-sharing policy
- Report message feature
- Admin moderation tools
- Future automated detection
- Future masked communication

The platform cannot guarantee complete prevention of contact exchange, but it shall reduce risk through design and policy.

# **17.34 Messaging Attachments**

If messaging attachments are enabled, allowed files should be restricted.

Recommended Version 1 allowed attachment types:

- PDF
- Image, optional
- Homework-related documents, optional

Disallowed examples:

- Executables
- Archives, unless approved
- Unknown file types
- Oversized files

All uploads must follow platform file security rules.

# **17.35 Message Reporting**

Students and instructors shall be able to report messages.

Report reasons may include:

- Off-platform solicitation
- Abuse or harassment
- Spam
- Inappropriate content
- Payment request
- Contact sharing
- Other

Reported messages should be visible to authorized administrators for review.

# **17.36 Admin Messaging Oversight**

Administrators may need limited oversight for safety and business protection.

Admin capabilities may include:

- View reported conversations
- Search flagged messages
- Review communication history for disputes
- Restrict messaging
- Suspend messaging access
- Take policy action
- Export conversation for investigation, where permitted

Admin access must be permission-controlled and audit logged.

# **17.37 Communication Audit**

Important communication events must be audit logged.

Examples:

- Notification template updated
- Critical notification sent
- Message reported
- Admin viewed reported conversation
- Messaging disabled for user
- WhatsApp/SMS provider failed
- Bulk notification sent
- Marketing campaign sent

# **17.38 Provider Strategy**

The notification system should be provider-agnostic where possible.

Potential provider categories:

- Email provider
- SMS provider
- WhatsApp provider
- Push provider, future
- In-app notification store

Version 1 provider choices may include:

- Resend for email
- SMS provider depending on India availability
- WhatsApp Business provider
- In-app notifications through database

Provider details belong to the technical blueprint.

# **17.39 Functional Requirements**

## **Notification Engine**

### **Notification Event Trigger**

**Priority:** Critical

The system shall trigger notifications based on defined platform events.

### **Multi-Channel Notification Support**

**Priority:** Critical

The system shall support email, SMS, WhatsApp, and in-app notification channels where configured.

### **Notification Priority**

**Priority:** High

The system shall support notification priority classification.

### **Notification Type**

**Priority:** High

The system shall classify notifications by type.

### **Notification Queueing**

**Priority:** Critical

The system shall queue notification delivery to avoid blocking user workflows.

### **Notification Delivery Log**

**Priority:** Critical

The system shall record delivery attempts for notifications.

### **Notification Retry**

**Priority:** High

The system shall retry failed notifications according to configured retry rules.

### **Duplicate Notification Prevention**

**Priority:** High

The system shall prevent duplicate notification delivery for the same event where applicable.

## **Templates**

### **Template Management**

**Priority:** High

Administrators shall be able to manage notification templates.

### **Template Variables**

**Priority:** High

The system shall support approved template variables.

### **Template Preview**

**Priority:** Medium

Administrators shall be able to preview notification templates.

### **Test Notification**

**Priority:** Medium

Administrators shall be able to send test notifications where permitted.

### **Template Localization**

**Priority:** Medium

The system shall support localized templates in future-ready design.

## **User Preferences**

### **Notification Preferences**

**Priority:** Medium

Users shall be able to manage optional notification preferences.

### **Critical Notification Enforcement**

**Priority:** Critical

The system shall send critical transactional notifications regardless of optional marketing preferences.

### **Marketing Opt-Out**

**Priority:** High

Users shall be able to opt out of marketing notifications where applicable.

## **Booking & Lesson Notifications**

### **Booking Confirmation Notification**

**Priority:** Critical

The system shall notify student and instructor after booking confirmation.

### **Lesson Reminder Notification**

**Priority:** Critical

The system shall send lesson reminders according to configured schedule.

### **Cancellation Notification**

**Priority:** Critical

The system shall notify affected participants after booking cancellation.

### **Reschedule Notification**

**Priority:** Critical

The system shall notify affected participants after booking rescheduling.

### **Meeting Link Notification**

**Priority:** High

The system shall notify users when meeting access is available according to access policy.

## **Wallet, Payment & Financial Notifications**

### **Payment Notification**

**Priority:** Critical

The system shall notify students about successful or failed payments.

### **Wallet Notification**

**Priority:** Critical

The system shall notify students about wallet credits, debits, refunds, and low balance.

### **Instructor Earning Notification**

**Priority:** High

The system shall notify instructors about earning and withdrawal events.

## **Homework & Learning Notifications**

### **Homework Notification**

**Priority:** High

The system shall notify students when homework is assigned, due soon, submitted, or reviewed.

### **Learning Plan Notification**

**Priority:** Medium

The system shall notify students and instructors about important Learning Plan updates.

## **Referral Notifications**

### **Referral Reward Notification**

**Priority:** Medium

The system shall notify students about referral reward status changes.

## **Messaging**

### **Controlled Student-Instructor Messaging**

**Priority:** High

The system shall support controlled messaging between students and instructors where eligibility rules are satisfied.

### **Messaging Eligibility Check**

**Priority:** Critical

The system shall prevent messaging between students and instructors unless a valid learning or booking relationship exists.

### **Message Context Linkage**

**Priority:** High

Messages shall be linked to a booking, learning plan, lesson, or approved communication context where applicable.

### **Message History**

**Priority:** High

The system shall preserve message history according to retention policy.

### **Message Read Status**

**Priority:** Medium

The system shall support message read status.

### **Message Reporting**

**Priority:** High

Students and instructors shall be able to report inappropriate messages.

### **Admin Review of Reported Messages**

**Priority:** High

Authorized administrators shall be able to review reported messages.

### **Messaging Restriction**

**Priority:** High

Authorized administrators shall be able to restrict messaging access for policy violations.

## **Administration**

### **Communication Log Search**

**Priority:** High

Administrators shall be able to search notification and communication logs.

### **Communication Reports**

**Priority:** Medium

Administrators shall be able to view communication delivery and engagement reports.

### **Provider Failure Alert**

**Priority:** High

The system shall alert administrators when communication provider failures exceed configured thresholds.

# **17.40 Business Rules**

- Critical transactional notifications shall not depend on marketing consent.
- Optional marketing notifications shall respect user opt-out preferences.
- Meeting links shall be shared only according to meeting access policy.
- Payment, wallet, booking, and security notifications shall be treated as critical.
- Student-instructor messaging shall be enabled only when a valid booking or learning relationship exists.
- Unrestricted pre-booking student-instructor chat is not allowed in Version 1.
- Messages must not be used for off-platform solicitation.
- Sharing personal contact information through platform messaging is prohibited by policy.
- Reported messages shall be reviewable by authorized administrators.
- Admin access to communication history must be permission-controlled and audit logged.
- Notification delivery attempts shall be logged.
- Failed critical notifications should be retried according to policy.
- Notification templates shall not expose confidential internal data.
- Communication records shall be retained according to platform retention policy.

# **17.41 Validation Rules**

- Notification recipient must be a valid user or authorized contact destination.
- Notification channel must be enabled before sending.
- Template variables must be approved and available for the notification context.
- Critical notifications cannot be suppressed by marketing opt-out.
- Messaging participants must satisfy eligibility rules.
- A message must reference a valid conversation or context.
- Message attachments must satisfy file type and size restrictions.
- Reported message reason is required.
- Admin messaging restriction action requires reason.
- Duplicate event references shall not generate duplicate notifications where idempotency is required.

# **17.42 User Workflows**

## **Booking Confirmation Notification**

- Booking is confirmed.
- System identifies student and instructor.
- System selects applicable notification templates.
- System queues notifications.
- Notifications are sent through configured channels.
- Delivery attempts are logged.
- In-app notifications appear in dashboards.

## **Lesson Reminder**

- Lesson reminder schedule reaches configured time.
- System checks booking status.
- System checks user notification preferences.
- System sends reminder through configured channels.
- Reminder includes lesson time and join action.
- Delivery status is logged.

## **Low Wallet Balance Notification**

- System detects upcoming recurring lesson.
- System checks wallet balance.
- Balance is insufficient or below threshold.
- System sends low-balance notification.
- Student receives recharge action.
- Notification is logged.

## **Homework Assigned Notification**

- Instructor assigns homework.
- System identifies student.
- System generates notification.
- Student receives in-app and configured channel notification.
- Homework appears on student dashboard.

## **Controlled Student-Instructor Message**

- Student opens lesson or learning plan context.
- System checks messaging eligibility.
- Student sends message.
- Message is stored.
- Instructor receives in-app notification.
- Optional email/WhatsApp notification may be sent.
- Conversation history is preserved.

## **Message Report**

- User reports a message.
- User selects reason.
- System creates report record.
- Admin is notified.
- Admin reviews message.
- Admin takes action where required.
- Action is audit logged.

## **Admin Updates Notification Template**

- Admin opens template management.
- Admin selects template.
- Admin edits content.
- System validates variables.
- Admin previews template.
- Admin saves template.
- Change is audit logged.

# **17.43 Exception Handling**

## **Notification Provider Failure**

The system shall retry delivery according to configured retry rules and alert administrators if failures persist.

## **Invalid Contact Destination**

The system shall record delivery failure and may prompt the user to update contact details.

## **Template Variable Missing**

The notification shall fail safely, log the error, and alert administrators for critical templates.

## **Duplicate Notification Event**

The system shall suppress duplicate delivery where idempotency rules apply.

## **Message Sent Without Eligibility**

The system shall block the message and display an access restriction message.

## **Prohibited Content Reported**

The system shall flag the message for admin review and may temporarily restrict messaging according to policy.

## **Late Reminder**

If a reminder is no longer useful because the lesson has already started or ended, the system shall suppress it.

# **17.44 Notifications and Communication Reports**

Reports may include:

- Notifications sent
- Notifications failed
- Delivery rate by channel
- Critical notification failures
- Provider performance
- Lesson reminder delivery
- WhatsApp delivery usage
- SMS usage
- Email delivery statistics
- In-app unread count
- Messaging usage
- Reported messages
- Messaging restrictions applied
- Campaign communication performance

# **17.45 Administrative Configuration**

Administrators shall be able to configure:

- Enabled notification channels
- Email provider settings
- SMS provider settings
- WhatsApp provider settings
- Notification templates
- Reminder timing
- Retry rules
- Critical notification rules
- User preference categories
- Marketing opt-out behavior
- Message eligibility rules
- Message attachment rules
- Report reasons
- Admin alert recipients
- Communication retention period
- Provider failure thresholds

# **17.46 Acceptance Criteria**

- The system sends booking, payment, wallet, meeting, homework, and lesson notifications through configured channels.
- Critical transactional notifications are delivered regardless of marketing opt-out preferences.
- Users can view in-app notifications with read/unread status and relevant action links.
- Notification templates can be managed by authorized administrators.
- Delivery attempts are logged with status and failure details.
- Student-instructor messaging is available only after valid booking or learning relationship eligibility.
- Messages can be reported and reviewed by authorized administrators.
- The system prevents duplicate notifications for the same event where applicable.
- Admins can monitor communication delivery performance and provider failures.
- Communication rules reduce off-platform leakage risk without blocking legitimate learning support.

# **17.47 Future Enhancements**

The Notifications, Communication & Messaging System is designed to support:

- Mobile push notifications
- AI-assisted notification personalization
- AI message moderation
- Automated contact-sharing detection
- Smart lesson reminder timing
- WhatsApp chatbot
- Student support inbox
- Instructor support inbox
- Parent notifications
- Bulk campaign messaging
- Advanced segmentation
- Multi-language templates
- Voice call reminders
- Interactive WhatsApp actions
- Message translation
- Conversation summaries
- Support ticket integration
- Communication risk scoring

# **17.48 Chapter Summary**

The Notifications, Communication & Messaging System is the engagement backbone of STEM Learning.

It ensures that students, instructors, and administrators receive timely and actionable updates across account, booking, payment, wallet, meeting, homework, referral, review, and earning workflows.

The module also defines controlled student-instructor messaging, ensuring that communication supports learning while reducing off-platform leakage risk.

By combining multi-channel notifications, in-app notification center, template management, delivery logs, messaging eligibility, reporting, and admin oversight, STEM Learning can create a professional, secure, and scalable communication experience.

#

# **CHAPTER 18 - REVIEWS, RATINGS, FEEDBACK & QUALITY ASSURANCE**

# **18.1 Introduction**

The Reviews, Ratings, Feedback & Quality Assurance module governs how students and instructors provide feedback after lessons, how reviews are displayed publicly, how quality signals are used, and how the platform maintains trust across the learning marketplace.

Reviews are not only social proof. In STEM Learning, they are part of the platform's quality assurance system. They influence instructor trust, marketplace ranking, student confidence, instructor improvement, admin monitoring, and long-term platform quality.

The module must ensure that reviews are authentic, fair, lesson-linked, moderated where necessary, and protected from abuse.

Only verified learning activity should generate public review opportunities.

# **18.2 Purpose**

The purpose of this module is to provide a trustworthy feedback system that helps:

- Students evaluate instructors.
- Instructors improve teaching quality.
- Administrators monitor marketplace quality.
- The platform detect service issues.
- The marketplace build credibility.
- Future recommendation systems improve instructor matching.

The system shall support ratings, written reviews, private feedback, moderation, quality alerts, instructor response, and analytics.

# **18.3 Business Objectives**

This module shall support the following business objectives:

- Build marketplace trust.
- Improve student booking confidence.
- Encourage instructor quality.
- Detect poor learning experiences.
- Support review-based marketplace signals.
- Reduce fake or abusive reviews.
- Support admin quality assurance.
- Enable instructor improvement.
- Improve demo-to-paid conversion.
- Prepare for future AI-assisted quality analysis.

# **18.4 Scope**

This chapter covers:

## **Reviews**

- Student-to-instructor reviews
- Instructor-to-student feedback
- Lesson-linked reviews
- Demo review policy
- Paid lesson review policy
- Public review display
- Private feedback

## **Ratings**

- Overall rating
- Teaching quality rating
- Communication rating
- Punctuality rating
- Preparedness rating
- Learning value rating

## **Moderation**

- Review approval
- Review rejection
- Review hiding
- Reported reviews
- Abuse detection
- Admin review

## **Quality Assurance**

- Instructor quality score
- Low-rating alerts
- No-show quality impact
- Cancellation quality impact
- Student feedback patterns
- Admin quality dashboard

# **18.5 Review Philosophy**

Reviews should be:

- Authentic
- Verified
- Constructive
- Respectful
- Lesson-linked
- Useful for future students
- Helpful for instructor improvement
- Protected from manipulation

The platform shall not allow random public reviews from users who have not completed lessons.

The recommended principle is:

Only verified completed lessons can generate review eligibility.

# **18.6 Review Eligibility**

A review may be created only when eligibility conditions are satisfied.

Eligibility conditions may include:

- Student account is active.
- Instructor account is active or historically valid.
- Lesson was completed.
- Booking was not cancelled.
- Booking was not invalidated.
- Payment or demo eligibility was valid.
- Review window has not expired.
- No duplicate review exists for the same booking by the same reviewer.

The system may allow separate policies for demo and paid lessons.

# **18.7 Review Types**

## **Public Review**

A public review may be shown on instructor marketplace profile.

Public reviews help future students evaluate instructors.

## **Private Feedback**

Private feedback is visible to administrators and optionally the instructor, depending on policy.

Private feedback helps quality assurance without exposing sensitive concerns publicly.

## **Instructor Feedback About Student**

Instructor feedback helps understand student engagement, preparedness, and behavior.

This feedback is normally private and should not appear publicly.

## **Internal Quality Note**

Administrators may add internal quality notes based on review patterns, complaints, or investigations.

Internal quality notes are never public.

# **18.8 Student Review of Instructor**

Students may review instructors after eligible completed lessons.

Review fields may include:

- Overall rating
- Teaching quality
- Communication
- Punctuality
- Preparedness
- Learning value
- Written review
- Private feedback
- Would recommend, optional
- Tags, optional

The platform may require an overall rating and make written review optional.

# **18.9 Instructor Feedback About Student**

Instructors may provide feedback about students after completed lessons.

Instructor feedback may include:

- Attendance
- Preparedness
- Homework completion
- Engagement
- Learning attitude
- Areas needing support
- Private notes for learning continuity

Instructor feedback should support learning quality and should not become a public student rating marketplace in Version 1.

# **18.10 Demo Lesson Review Policy**

Demo lesson reviews are configurable.

Possible policies:

## **Option A - Demo Reviews Disabled**

Demo lessons do not generate public reviews.

## **Option B - Demo Reviews Private Only**

Demo feedback is collected privately for quality and conversion analysis.

## **Option C - Demo Reviews Public**

Demo lesson reviews may appear publicly if approved.

Recommended Version 1:

Demo lesson feedback should be private or limited, not heavily weighted in public instructor rating.

This prevents free demo abuse and protects review quality.

# **18.11 Paid Lesson Review Policy**

Paid lesson reviews are stronger trust signals.

Recommended Version 1:

- Paid completed lessons may generate review eligibility.
- One public review per completed booking by the student.
- Review window is configurable.
- Reviews may be moderated based on platform policy.
- Aggregate rating appears on instructor public profile.

# **18.12 Rating Dimensions**

The platform may support multiple rating dimensions.

Suggested Version 1 dimensions:

## **Overall Rating**

General student satisfaction.

## **Teaching Quality**

How effectively the instructor explained the topic.

## **Communication**

How clearly and professionally the instructor communicated.

## **Punctuality**

Whether the instructor joined and conducted class on time.

## **Preparedness**

Whether the instructor was ready for the lesson.

## **Learning Value**

Whether the student felt the lesson was useful.

The public rating may show only overall rating initially, while detailed dimensions support analytics.

# **18.13 Rating Scale**

The platform shall support a configurable rating scale.

Recommended Version 1:

1 to 5 star rating

Where:

- 1 = Poor
- 2 = Below expectations
- 3 = Satisfactory
- 4 = Good
- 5 = Excellent

The platform may later support half-star averages for display.

# **18.14 Written Reviews**

Students may submit written review text.

Written reviews should:

- Be related to actual learning experience.
- Avoid personal attacks.
- Avoid sharing private contact information.
- Avoid offensive content.
- Avoid false claims.
- Avoid promotional spam.

Reviews violating policy may be hidden, rejected, or escalated.

# **18.15 Review Tags**

The platform may support predefined review tags to improve consistency.

Examples:

Positive tags:

- Explains clearly
- Patient
- Well prepared
- Great for beginners
- Good communication
- Helpful homework
- On time
- Encouraging

Improvement tags:

- Needs clearer explanation
- Pace too fast
- Pace too slow
- Technical issues
- Late joining
- Less structured

Tags help analytics and reduce reliance on long written text.

# **18.16 Review Window**

The platform shall define a review submission window.

Examples:

- Within 7 days after lesson completion
- Within 14 days after lesson completion
- Within 30 days after lesson completion

After the review window expires, the student may no longer submit a review unless an administrator allows an exception.

# **18.17 Review Moderation**

The platform may use moderation before or after publishing.

Possible moderation models:

## **Pre-Moderation**

Review is reviewed by admin before public display.

## **Post-Moderation**

Review is published first and may be removed later if reported.

## **Risk-Based Moderation**

Low-risk reviews publish automatically; flagged reviews require admin review.

Recommended Version 1:

Risk-based moderation with admin review for flagged reviews.

This balances speed and safety.

# **18.18 Review Status Definitions**

Reviews may have the following statuses:

## **Draft**

Review started but not submitted.

## **Submitted**

Review submitted by user.

## **Published**

Review is visible publicly.

## **Private**

Review is stored but not publicly visible.

## **Flagged**

Review requires review or has been reported.

## **Hidden**

Review is not publicly visible.

## **Rejected**

Review was rejected for policy violation.

## **Archived**

Review is retained historically but no longer active.

# **18.19 Review Reporting**

Users may report reviews.

Report reasons may include:

- Fake or misleading
- Abusive language
- Personal information
- Off-platform solicitation
- Hate or harassment
- Spam
- Irrelevant content
- Privacy concern

Reported reviews should enter moderation queue.

# **18.20 Instructor Response to Review**

The platform may allow instructors to respond to public reviews.

Rules:

- Response must follow communication policy.
- Response may be moderated.
- Response should be professional.
- Response should not expose private student details.
- Response should not pressure the student.

Recommended Version 1:

- Instructor response may be future scope, or
- Enabled only after moderation.

# **18.21 Review Editing**

Students may edit reviews within a configured period.

Rules:

- Editing window is configurable.
- Edited reviews may require re-moderation.
- Edit history may be retained.
- Review cannot be edited after admin dispute closure unless permitted.

Recommended Version 1:

- Allow limited edit window before publication or shortly after submission.

# **18.22 Review Deletion**

Reviews should not be physically deleted except under legal or compliance necessity.

Preferred handling:

- Hide review
- Reject review
- Archive review
- Retain audit trail

This preserves marketplace integrity and moderation history.

# **18.23 Public Review Display**

Instructor public profiles may display:

- Average rating
- Total reviews
- Recent reviews
- Review date
- Subject context, optional
- Lesson type, optional
- Review tags
- Student first name or masked name
- Verified lesson badge

The profile should not display sensitive student information.

# **18.24 Rating Aggregation**

The system shall calculate instructor rating aggregates.

Possible aggregates:

- Overall average rating
- Number of reviews
- Rating distribution
- Recent rating trend
- Subject-specific rating, future
- Demo vs paid rating separation
- Rating by teaching dimension

Rating calculation should exclude rejected, hidden, or invalid reviews.

# **18.25 Quality Assurance Signals**

Quality assurance should not depend only on reviews.

The platform may consider multiple signals:

- Average rating
- Low-rating frequency
- Instructor no-show rate
- Student no-show context
- Cancellation rate
- Rescheduling frequency
- Lesson completion rate
- Demo-to-paid conversion
- Student retention
- Homework assignment consistency
- Response time
- Reported messages
- Technical issue rate

These signals support admin monitoring and marketplace ranking.

# **18.26 Instructor Quality Score**

The platform may maintain an internal instructor quality score.

The score may include:

- Ratings
- Review trends
- Completion rate
- No-show rate
- Cancellation rate
- Response time
- Retention
- Student complaints
- Admin review outcomes

The quality score is internal and should not be shown directly unless the platform chooses to expose simplified badges later.

# **18.27 Quality Alerts**

The system may generate quality alerts.

Examples:

- Instructor receives multiple low ratings.
- Instructor no-show rate increases.
- Instructor receives repeated complaints.
- Demo conversion is very low.
- Cancellation rate is high.
- Student retention is poor.
- Technical issue reports increase.

Quality alerts help administrators intervene early.

# **18.28 Admin Quality Dashboard**

Administrators should be able to view quality metrics.

Dashboard may include:

- Low-rated instructors
- Highly rated instructors
- Review queue
- Flagged reviews
- Quality alerts
- Instructor no-show patterns
- Cancellation patterns
- Student complaints
- Demo conversion quality
- Retention indicators

# **18.29 Instructor Quality Feedback**

The platform should help instructors improve.

Instructor-facing quality insights may include:

- Average rating
- Review highlights
- Areas for improvement
- Punctuality score
- Student feedback tags
- Completion consistency
- Response time
- Suggested improvements, future

The system should avoid exposing sensitive internal risk scoring directly.

# **18.30 Student Feedback Privacy**

The platform must protect student privacy.

Public reviews should not expose:

- Full student identity unless permitted.
- Student contact details.
- Student age, if collected.
- Private learning concerns.
- Payment details.
- Personal disputes.
- Sensitive personal information.

Students may appear as:

- First name only
- Initials
- Masked name
- Anonymous, if allowed

# **18.31 Review Abuse Prevention**

The system shall reduce review abuse.

Abuse examples:

- Fake reviews
- Duplicate reviews
- Retaliatory reviews
- Reviews without completed lesson
- Spam reviews
- Contact-sharing in review text
- Offensive content
- Competitor manipulation
- Reward-based fake reviews

Abuse prevention includes eligibility checks, moderation, reporting, and audit logs.

# **18.32 Review Impact on Marketplace**

Reviews may influence marketplace ranking.

Marketplace use cases:

- Higher trust profiles
- Recommended instructor ranking
- Review count display
- Rating filter
- Quality badges, future
- Low-quality visibility reduction

Marketplace ranking should not rely only on ratings, as this may disadvantage new instructors.

# **18.33 New Instructor Fairness**

New instructors may not have reviews initially.

The platform should avoid permanently disadvantaging new instructors.

Possible fairness mechanisms:

- New instructor visibility boost
- Verified profile badge
- Qualification display
- Demo availability emphasis
- Early review collection
- Controlled marketplace exposure

This supports marketplace growth.

# **18.34 Feedback for Learning Plans**

Feedback may contribute to Learning Plans.

Examples:

- Student says pace is too fast.
- Instructor notes student needs more practice.
- Student wants more homework.
- Student wants exam-focused learning.
- Instructor recommends roadmap adjustment.

Feedback should help improve future lessons.

# **18.35 AI Readiness**

Future AI services may use reviews and feedback to:

- Summarize instructor strengths.
- Detect recurring quality issues.
- Recommend instructor improvements.
- Match students by teaching style.
- Identify student satisfaction risks.
- Generate quality insights.
- Moderate review content.
- Predict retention risk.

AI-generated quality insights should be reviewable and should not automatically penalize instructors without governance.

# **18.36 Functional Requirements**

## **Review Eligibility**

### **Verified Lesson Review Eligibility**

**Priority:** Critical

The system shall allow reviews only for eligible completed lessons.

### **Duplicate Review Prevention**

**Priority:** Critical

The system shall prevent duplicate reviews for the same booking by the same reviewer.

### **Review Window Enforcement**

**Priority:** High

The system shall enforce a configurable review submission window.

### **Demo Review Policy**

**Priority:** Medium

The system shall support configurable demo lesson review policy.

## **Student Review**

### **Student Instructor Rating**

**Priority:** Critical

Students shall be able to rate instructors after eligible completed lessons.

### **Written Review**

**Priority:** High

Students shall be able to submit written review text where enabled.

### **Rating Dimensions**

**Priority:** High

The system shall support configurable rating dimensions.

### **Review Tags**

**Priority:** Medium

The system shall support predefined review tags.

### **Private Feedback**

**Priority:** High

Students shall be able to submit private feedback separate from public review.

## **Instructor Feedback**

### **Instructor Student Feedback**

**Priority:** Medium

Instructors shall be able to submit private feedback about student engagement after eligible lessons.

### **Learning Plan Feedback Linkage**

**Priority:** Medium

Instructor feedback may be linked to the student's Learning Plan where applicable.

## **Moderation**

### **Review Moderation Status**

**Priority:** High

The system shall support review moderation statuses.

### **Flagged Review Queue**

**Priority:** High

Flagged reviews shall appear in an admin moderation queue.

### **Review Approval**

**Priority:** High

Authorized administrators shall be able to approve reviews.

### **Review Rejection**

**Priority:** High

Authorized administrators shall be able to reject reviews with reason.

### **Review Hiding**

**Priority:** High

Authorized administrators shall be able to hide reviews from public display.

### **Review Reporting**

**Priority:** High

Users shall be able to report reviews for policy violations.

## **Public Display**

### **Public Review Display**

**Priority:** Critical

Published reviews shall be displayed on instructor public profiles according to policy.

### **Verified Lesson Badge**

**Priority:** High

Public reviews shall indicate that they are linked to verified lessons where applicable.

### **Student Identity Masking**

**Priority:** Critical

The system shall protect student identity on public reviews according to privacy settings.

## **Rating Aggregation**

### **Average Rating Calculation**

**Priority:** Critical

The system shall calculate average instructor rating from eligible published reviews.

### **Review Count**

**Priority:** Critical

The system shall display total eligible review count.

### **Rating Distribution**

**Priority:** Medium

The system shall support rating distribution analytics.

### **Exclusion of Invalid Reviews**

**Priority:** Critical

Rejected, hidden, or invalid reviews shall not affect public rating averages.

## **Quality Assurance**

### **Quality Signals**

**Priority:** High

The system shall collect quality signals from reviews, ratings, no-shows, cancellations, and completion behavior.

### **Quality Alerts**

**Priority:** High

The system shall generate alerts for repeated low ratings or quality concerns.

### **Admin Quality Dashboard**

**Priority:** High

Administrators shall have access to quality assurance dashboard metrics.

### **Instructor Quality Insights**

**Priority:** Medium

Instructors shall be able to view quality insights from their own reviews and feedback.

## **Instructor Response**

### **Instructor Review Response**

**Priority:** Low

The system may allow instructors to respond to public reviews where enabled.

### **Response Moderation**

**Priority:** Low

Instructor responses may require moderation according to policy.

## **Audit**

### **Review Audit Log**

**Priority:** Critical

The system shall audit log moderation actions, review hiding, rejection, and admin changes.

### **Review History Preservation**

**Priority:** High

The system shall preserve review history according to retention policy.

# **18.37 Business Rules**

- Only verified completed lessons may generate review eligibility.
- A student may submit only one review per eligible booking.
- Cancelled bookings shall not generate public review eligibility.
- Instructor no-show lessons may allow private feedback or complaint flow rather than standard public review, according to policy.
- Demo lesson review handling shall be configurable.
- Rejected, hidden, or invalid reviews shall not affect public rating averages.
- Public reviews shall not expose sensitive student information.
- Review text must not contain personal contact information or off-platform solicitation.
- Reviews may be moderated for safety, privacy, relevance, and abuse prevention.
- Admin review moderation actions require reason and audit log.
- Instructor quality score shall be internal unless explicitly configured for public display.
- Marketplace ranking may use review signals but shall not rely exclusively on rating average.
- Instructors shall not be able to delete student reviews directly.
- Students shall not be able to create reviews for instructors they have not learned from.

# **18.38 Validation Rules**

- Review must reference a valid completed booking.
- Reviewer must be a participant in the booking.
- Review rating must fall within configured rating scale.
- Review submission must occur within configured review window.
- Only one review may exist per reviewer and booking.
- Written review length must follow configured minimum and maximum limits.
- Reported review must include a report reason.
- Admin moderation action requires reason.
- Public review display requires published status.
- Hidden or rejected reviews must not be included in public rating calculation.

# **18.39 User Workflows**

## **Student Submits Review**

- Lesson is completed.
- System marks review eligibility.
- Student receives review request.
- Student opens review form.
- Student selects rating.
- Student optionally writes review and selects tags.
- Student submits review.
- System applies moderation rules.
- Review is published or queued.
- Rating aggregates update if published.

## **Student Submits Private Feedback**

- Student opens completed lesson.
- Student selects private feedback option.
- Student submits feedback.
- Feedback is stored privately.
- Admin may review feedback.
- Instructor may view feedback only if policy allows.

## **Instructor Gives Student Feedback**

- Lesson is completed.
- Instructor opens lesson completion panel.
- Instructor records student engagement feedback.
- Feedback is linked to lesson or Learning Plan.
- Feedback remains private and supports future learning.

## **Review Moderation**

- Review is submitted or flagged.
- System places review in moderation queue if required.
- Admin reviews content.
- Admin approves, hides, rejects, or requests action.
- System records moderation decision.
- Public display updates accordingly.

## **Review Reporting**

- User views public review.
- User reports review.
- User selects report reason.
- System flags review.
- Admin receives moderation task.
- Admin reviews and takes action.

## **Quality Alert**

- System detects repeated low ratings or quality issue.
- Quality alert is generated.
- Admin reviews instructor profile and lesson history.
- Admin may contact instructor, place warning, or take platform action.
- Action is logged.

# **18.40 Exception Handling**

## **Review Window Expired**

The system shall prevent review submission unless an authorized admin grants exception.

## **Duplicate Review Attempt**

The system shall block duplicate review and show existing review status.

## **Review Contains Prohibited Content**

The review shall be flagged, hidden, or rejected according to moderation policy.

## **Rating Aggregation Failure**

The system shall preserve review data and notify administrators if rating recalculation fails.

## **Instructor Disputes Review**

The system may allow instructor to report review for moderation but shall not allow direct deletion.

## **Review Linked Booking Invalidated**

If a booking is later invalidated due to fraud or correction, the related review may be hidden and excluded from rating.

# **18.41 Notifications**

Review and feedback notifications include:

## **Student**

- Review request after completed lesson
- Review submitted
- Review published
- Review rejected or hidden, where appropriate
- Instructor response, future

## **Instructor**

- New public review received
- Private feedback available, where enabled
- Low rating alert, where appropriate
- Review response approved, future

## **Administrator**

- Review flagged
- Review reported
- Low-rating quality alert
- Instructor quality concern
- Suspicious review pattern
- Moderation pending

# **18.42 Reports & Analytics**

Review and quality reports may include:

- Average instructor rating
- Review count
- Rating distribution
- Low-rated lessons
- Review submission rate
- Demo feedback rate
- Paid lesson review rate
- Instructor response rate, future
- Reported reviews
- Rejected reviews
- Hidden reviews
- Quality alerts
- Instructor no-show impact
- Cancellation impact
- Subject-wise rating trends
- Country-wise rating trends
- Student satisfaction trends
- Demo-to-paid quality correlation

# **18.43 Administrative Configuration**

Administrators shall be able to configure:

- Review enabled/disabled
- Demo review policy
- Paid lesson review policy
- Review window
- Rating scale
- Rating dimensions
- Review tags
- Written review required/optional
- Minimum review length
- Maximum review length
- Moderation model
- Report reasons
- Student identity display format
- Instructor response enabled/disabled
- Quality alert thresholds
- Rating aggregation rules
- Review notification templates
- Review export permissions

# **18.44 Acceptance Criteria**

- Students can review instructors only after eligible completed lessons.
- Duplicate reviews for the same booking are prevented.
- Public instructor ratings are calculated only from eligible published reviews.
- Student identity is protected on public reviews.
- Administrators can moderate, hide, reject, and audit reviews.
- Reported reviews enter an admin review workflow.
- Instructors can view review summaries and quality insights without controlling review deletion.
- The system can generate quality alerts based on low ratings and other quality signals.
- Review data contributes to instructor marketplace trust and quality assurance reporting.
- Private feedback is separated from public reviews and used for learning improvement or quality review.

# **18.45 Future Enhancements**

This module is designed to support future expansion, including:

- AI-assisted review moderation
- AI-generated instructor quality summaries
- AI teaching-style extraction
- Sentiment analysis
- Review translation
- Student video testimonials
- Instructor response workflow
- Review helpfulness voting
- Subject-specific rating profiles
- Parent feedback
- Group class feedback
- Automated quality coaching
- Instructor improvement plans
- Quality certification badges
- Public achievement badges
- Review fraud scoring
- Marketplace ranking optimization
- Learning outcome-based ratings

# **18.46 Chapter Summary**

The Reviews, Ratings, Feedback & Quality Assurance module strengthens trust across STEM Learning.

It ensures that only verified learning experiences generate reviews, protects student privacy, gives instructors useful feedback, and gives administrators tools to monitor marketplace quality.

By combining public reviews, private feedback, moderation, rating aggregation, quality signals, and admin dashboards, the platform can build a trustworthy marketplace while continuously improving learning quality.

This module also prepares STEM Learning for future AI-assisted quality analysis, instructor coaching, personalized recommendations, and trust-based marketplace ranking.

PART F

# **PART F - PLATFORM ADMINISTRATION & OPERATIONS**

# **CHAPTER 19 - REPORTS, ANALYTICS & BUSINESS INTELLIGENCE**

# **19.1 Introduction**

The Reports, Analytics & Business Intelligence module defines how STEM Learning collects, organizes, analyzes, displays, and exports operational and business data.

The platform generates important data across every major domain, including students, instructors, bookings, learning plans, lessons, homework, meetings, wallet transactions, payments, referrals, reviews, notifications, and support operations.

This module enables administrators and business stakeholders to monitor performance, identify risks, understand growth, track revenue, improve instructor quality, evaluate marketplace health, and make data-driven decisions.

Reports must be accurate, permission-controlled, exportable where permitted, and designed to support both daily operations and strategic business planning.

# **19.2 Purpose**

The purpose of this module is to provide a centralized reporting and analytics framework for STEM Learning.

The module must support:

- Admin dashboards
- Business KPIs
- Operational reports
- Financial reports
- Marketplace analytics
- Learning analytics
- Instructor analytics
- Student analytics
- Booking analytics
- Wallet analytics
- Payment analytics
- Referral analytics
- Review and quality reports
- Exportable reports
- Permission-controlled visibility

# **19.3 Business Objectives**

This module shall support the following business objectives:

- Give administrators a clear view of platform health.
- Track student acquisition, activity, and retention.
- Monitor instructor quality and utilization.
- Track demo, paid, and recurring lesson performance.
- Monitor revenue, wallet movement, and payments.
- Evaluate referral campaign effectiveness.
- Improve marketplace discovery and conversion.
- Detect operational risks early.
- Support financial reconciliation.
- Enable data-driven growth decisions.

# **19.4 Scope**

This chapter covers:

## **Dashboards**

- Executive dashboard
- Operations dashboard
- Finance dashboard
- Instructor performance dashboard
- Student engagement dashboard
- Marketplace dashboard
- Learning analytics dashboard

## **Reports**

- Student reports
- Instructor reports
- Booking reports
- Lesson reports
- Revenue reports
- Wallet reports
- Payment reports
- Referral reports
- Review reports
- Meeting reports
- Notification reports

## **Analytics**

- KPIs
- Trends
- Conversion metrics
- Retention metrics
- Utilization metrics
- Quality metrics
- Country-wise performance
- Subject-wise performance

## **Exports**

- CSV export
- Excel export, future
- PDF reports, future
- Role-based export permissions

# **19.5 Reporting Philosophy**

Reports should be:

- Accurate
- Timely
- Actionable
- Permission-controlled
- Filterable
- Exportable where permitted
- Consistent across modules
- Easy to understand
- Useful for decision-making

The system should avoid vanity metrics without business value.

Every report should answer at least one operational or strategic question.

# **19.6 Operational Reports vs Business Intelligence**

The platform shall separate reports into two broad categories.

## **Operational Reports**

Operational reports help teams manage daily work.

Examples:

- Today's bookings
- Failed payments
- Instructor no-shows
- Pending instructor approvals
- Homework pending review
- Meeting creation failures
- Withdrawal requests
- Low wallet balance students

## **Business Intelligence**

Business intelligence helps leadership understand growth and performance.

Examples:

- Monthly revenue trend
- Demo-to-paid conversion
- Student retention
- Instructor utilization
- Referral ROI
- Country-wise growth
- Subject demand
- Marketplace conversion

Both categories are important but serve different users.

# **19.7 Dashboard Types**

The platform may provide multiple dashboard views.

## **Executive Dashboard**

For business owners and senior administrators.

Focus:

- Revenue
- Growth
- Bookings
- Active students
- Active instructors
- Conversion
- Retention
- Marketplace health

## **Operations Dashboard**

For daily platform operations.

Focus:

- Today's lessons
- Pending approvals
- Failed jobs
- Support issues
- No-shows
- Cancellations
- Meeting failures
- Notification failures

## **Finance Dashboard**

For finance and administration teams.

Focus:

- Razorpay payments
- Wallet recharge
- Wallet debit
- Refund credits
- Instructor earnings
- Withdrawal requests
- Payment reconciliation
- Wallet liability

## **Marketplace Dashboard**

For growth and marketplace management.

Focus:

- Search activity
- Profile views
- Demo bookings
- Paid bookings
- Conversion rates
- Popular subjects
- Instructor availability
- Waitlist demand

## **Learning Dashboard**

For academic quality monitoring.

Focus:

- Learning plans
- Homework completion
- Milestones
- Student progress
- Instructor feedback
- Learning consistency

## **Instructor Quality Dashboard**

For instructor management.

Focus:

- Ratings
- Reviews
- Completion rate
- No-show rate
- Cancellation rate
- Retention
- Demo-to-paid conversion
- Quality alerts

# **19.8 Key Performance Indicators**

The platform shall support configurable KPIs.

Suggested core KPIs:

## **Growth KPIs**

- Registered students
- Verified students
- Active students
- Registered instructors
- Approved instructors
- Active instructors
- New users by period

## **Marketplace KPIs**

- Instructor profile views
- Search-to-profile conversion
- Profile-to-demo conversion
- Demo-to-paid conversion
- Paid booking conversion
- Favorite additions
- Waitlist count

## **Learning KPIs**

- Active Learning Plans
- Lessons completed
- Homework completion rate
- Milestones achieved
- Progress reviews completed
- Recurring lesson continuation rate

## **Financial KPIs**

- Total revenue
- Wallet recharge value
- Wallet debit value
- Razorpay payment success rate
- Refund amount
- Instructor earnings
- Withdrawal amount
- Wallet liability

## **Quality KPIs**

- Average instructor rating
- Low rating count
- Instructor no-show rate
- Student no-show rate
- Cancellation rate
- Technical issue rate
- Review submission rate

# **19.9 Student Analytics**

Student analytics help understand acquisition, activity, retention, and learning behavior.

Reports may include:

- Total students
- New students
- Verified students
- Active students
- Inactive students
- Students by country
- Students by subject interest
- Students with active Learning Plans
- Students with wallet balance
- Students with recurring lessons
- Students at risk of inactivity
- Student lifetime booking count
- Student lifetime value, future

# **19.10 Instructor Analytics**

Instructor analytics help monitor quality, utilization, and earnings.

Reports may include:

- Total instructors
- Pending instructor applications
- Approved instructors
- Active instructors
- Suspended instructors
- Vacation mode instructors
- Instructor availability hours
- Instructor booked hours
- Utilization rate
- Demo bookings
- Paid bookings
- Completed lessons
- No-show rate
- Average rating
- Review count
- Earnings
- Withdrawals
- Demo-to-paid conversion

# **19.11 Booking Analytics**

Booking analytics track lesson demand and platform conversion.

Reports may include:

- Total bookings
- Demo bookings
- Paid bookings
- Recurring bookings
- Completed bookings
- Cancelled bookings
- Rescheduled bookings
- No-show bookings
- Technical issue bookings
- Booking by subject
- Booking by instructor
- Booking by country
- Booking by duration
- Booking by day/time
- Booking conversion rate
- Repeat booking rate

# **19.12 Demo Analytics**

Demo lessons are a major conversion channel.

Reports may include:

- Demo bookings
- Demo completion rate
- Demo cancellation rate
- Demo no-show rate
- Demo-to-paid conversion
- Instructor-wise demo conversion
- Subject-wise demo conversion
- Country-wise demo conversion
- Time-to-paid conversion after demo
- Demo demand by day/time

These analytics help improve instructor selection, onboarding, and marketplace ranking.

# **19.13 Recurring Lesson Analytics**

Recurring lessons are important for retention and predictable revenue.

Reports may include:

- Active recurring plans
- New recurring schedules
- Cancelled recurring schedules
- Paused recurring schedules
- Recurring lesson completion rate
- Recurring wallet deduction success rate
- Recurring payment pending count
- Low wallet balance recurring students
- Average recurring duration
- Recurring retention trend

# **19.14 Learning Analytics**

Learning analytics measure educational engagement.

Reports may include:

- Active Learning Plans
- Completed Learning Plans
- Learning goals created
- Milestones achieved
- Homework assigned
- Homework submitted
- Homework completion rate
- Progress reviews completed
- Curriculum progress
- Subject progress
- Student learning consistency
- Instructor learning activity contribution

Learning analytics should support education quality, not only commercial performance.

# **19.15 Homework & Resource Analytics**

Homework and resource reports may include:

- Homework assigned
- Homework submitted
- Homework overdue
- Homework reviewed
- Average review time
- Student completion rate
- Instructor assignment frequency
- Resource usage
- Most used resources
- Subject-wise homework activity
- Learning Plan-linked homework

# **19.16 Meeting Analytics**

Meeting analytics help evaluate virtual classroom reliability.

Reports may include:

- Meetings created
- Meeting creation failures
- Meeting attendance
- Student join rate
- Instructor join rate
- Average join delay
- Average lesson duration
- Recording success rate
- Technical issue reports
- Admin observer sessions
- Provider reliability
- No-show support metrics

# **19.17 Wallet Analytics**

Wallet analytics support financial monitoring.

Reports may include:

- Total wallet balance liability
- Wallet recharges
- Wallet debits
- Refund credits
- Referral credits
- Promotional credits
- Manual adjustments
- Failed recurring deductions
- Low wallet balance students
- Wallet usage by booking type
- Country-wise wallet activity
- Currency-wise wallet activity, future

# **19.18 Payment Analytics**

Payment analytics focus on Razorpay checkout and reconciliation.

Reports may include:

- Payment attempts
- Successful payments
- Failed payments
- Payment success rate
- Wallet recharge payments
- Direct booking payments
- Average payment amount
- Razorpay reference tracking
- Pending payments
- Payment mismatches
- Duplicate callback events
- Payment reconciliation issues

# **19.19 Revenue Analytics**

Revenue analytics help leadership understand business performance.

Reports may include:

- Gross booking value
- Wallet recharge value
- Lesson revenue
- Revenue by subject
- Revenue by country
- Revenue by instructor
- Revenue by lesson duration
- Demo-to-paid revenue contribution
- Recurring lesson revenue
- Refund impact
- Promotional credit impact
- Referral reward cost
- Net revenue estimate, future

Version 1 revenue reporting should remain INR-first.

# **19.20 Instructor Earnings & Settlement Analytics**

Reports may include:

- Total instructor earnings
- Pending earnings
- Eligible earnings
- Held earnings
- Withdrawable balance
- Withdrawal requests
- Approved withdrawals
- Paid withdrawals
- Rejected withdrawals
- Instructor-wise earning summary
- Subject-wise payout summary
- Incentive payout summary
- Demo conversion incentive payouts
- Settlement batch report

# **19.21 Referral Analytics**

Referral analytics measure campaign performance and reward cost.

Reports may include:

- Referral codes generated
- Referral registrations
- Verified referred students
- Referred paid students
- Referral conversion rate
- Reward credits issued
- Pending rewards
- Held rewards
- Rejected rewards
- Top referrers
- Campaign performance
- Referral-generated revenue
- Referral reward cost
- Abuse flags

# **19.22 Review & Quality Analytics**

Review and quality reports may include:

- Average instructor rating
- Review count
- Rating distribution
- Low-rated instructors
- Review submission rate
- Reported reviews
- Hidden reviews
- Rejected reviews
- Quality alerts
- Instructor no-show rate
- Cancellation pattern
- Technical issue pattern
- Student satisfaction trend
- Demo feedback trend

# **19.23 Notification Analytics**

Notification analytics help monitor communication reliability.

Reports may include:

- Notifications sent
- Notifications delivered
- Notifications failed
- Delivery rate by channel
- Email failure rate
- SMS failure rate
- WhatsApp failure rate
- Critical notification failures
- Reminder delivery rate
- Provider failure trend
- In-app unread count
- Message reports

# **19.24 Marketplace Discovery Analytics**

Marketplace analytics help improve search and conversion.

Reports may include:

- Search terms
- Search volume
- Zero-result searches
- Filter usage
- Profile views
- Favorite additions
- Waitlist joins
- Subject demand
- Instructor demand
- Search-to-profile conversion
- Profile-to-booking conversion
- Recommended instructor performance
- Featured instructor performance

# **19.25 Country & Regional Analytics**

Country-wise analytics support regional expansion.

Reports may include:

- Students by country
- Instructors by country
- Bookings by country
- Revenue by country
- Subject demand by country
- Currency-related reporting, future
- Timezone demand patterns
- Payment success by country
- Referral performance by country
- Review trends by country

For Version 1, INR reporting remains primary, but country-level user and demand analytics should still be captured.

# **19.26 Time-Based Reporting**

Reports should support time-based filters.

Common filters:

- Today
- Yesterday
- Last 7 days
- Last 30 days
- This month
- Previous month
- This quarter
- This year
- Custom date range

The system should clearly define whether reports use:

- Booking date
- Payment date
- Lesson date
- Completion date
- Wallet transaction date
- Registration date

# **19.27 Report Filters**

Reports should support relevant filters.

Common filters:

- Date range
- Country
- Subject
- Education level
- Instructor
- Student
- Booking status
- Payment status
- Wallet transaction type
- Referral campaign
- Review rating
- Lesson type
- Duration
- User status

Filters should be permission-aware.

# **19.28 Report Export**

Authorized users may export reports.

Supported Version 1 export:

- CSV

Future export options:

- Excel
- PDF
- Scheduled email reports
- Dashboard snapshots
- API exports

Exported reports must respect permissions and privacy rules.

# **19.29 Report Permissions**

Report access must be permission-controlled.

Examples:

- Finance users can view payment, wallet, settlement, and withdrawal reports.
- Academic admins can view learning and homework reports.
- Marketplace admins can view discovery and conversion reports.
- Support users can view operational reports but not sensitive financial summaries.
- Super administrators can view all reports.

Sensitive data should be masked where appropriate.

# **19.30 Data Privacy in Reports**

Reports must protect sensitive data.

Rules:

- Avoid exposing unnecessary personal information.
- Mask student contact details unless required.
- Mask payout details.
- Restrict financial data to authorized roles.
- Restrict private feedback visibility.
- Audit log sensitive exports.
- Avoid exposing instructor compensation to unauthorized roles.

# **19.31 Report Accuracy and Data Freshness**

Reports may be real-time, near real-time, or batch-generated.

Examples:

## **Real-Time**

- Today's bookings
- Failed payments
- Pending withdrawals
- Meeting failures

## **Near Real-Time**

- Wallet balances
- Booking counts
- Notification failures

## **Batch or Scheduled**

- Monthly revenue
- Instructor performance
- Learning analytics
- Retention analytics

Each report should indicate freshness where necessary.

# **19.32 KPI Snapshots**

The system may store KPI snapshots for faster reporting.

Examples:

- Daily booking count
- Daily revenue
- Daily active students
- Daily instructor utilization
- Daily wallet liability
- Daily payment success rate

KPI snapshots improve performance and enable trend charts.

# **19.33 Analytics Events**

The platform should capture structured analytics events.

Examples:

- Student registered
- Instructor profile viewed
- Search performed
- Filter applied
- Favorite added
- Demo booked
- Paid lesson booked
- Lesson completed
- Wallet recharged
- Review submitted
- Referral reward credited

Analytics events should not replace transactional records but may support behavior analysis.

# **19.34 Admin Report Builder**

A future report builder may allow authorized administrators to create custom reports.

Possible capabilities:

- Select data source
- Choose columns
- Apply filters
- Save report
- Export report
- Schedule report

Version 1 may provide predefined reports only.

# **19.35 Functional Requirements**

## **Dashboard**

### **Executive Dashboard**

**Priority:** High

The system shall provide an executive dashboard showing key business KPIs.

### **Operations Dashboard**

**Priority:** Critical

The system shall provide an operations dashboard for daily platform monitoring.

### **Finance Dashboard**

**Priority:** Critical

The system shall provide finance dashboard metrics for payments, wallet, revenue, settlement, and withdrawals.

### **Marketplace Dashboard**

**Priority:** High

The system shall provide marketplace dashboard metrics for discovery, search, profile views, and booking conversion.

### **Learning Dashboard**

**Priority:** High

The system shall provide learning analytics for Learning Plans, homework, milestones, and academic progress.

## **Student & Instructor Reports**

### **Student Report**

**Priority:** High

The system shall provide student reports including registration, verification, activity, bookings, and retention indicators.

### **Instructor Report**

**Priority:** High

The system shall provide instructor reports including status, availability, bookings, utilization, ratings, earnings, and quality indicators.

### **Instructor Utilization Report**

**Priority:** High

The system shall calculate instructor utilization based on available hours and booked hours.

## **Booking Reports**

### **Booking Report**

**Priority:** Critical

The system shall provide booking reports by type, status, subject, instructor, country, and date range.

### **Demo Conversion Report**

**Priority:** High

The system shall provide demo-to-paid conversion reporting.

### **Recurring Lesson Report**

**Priority:** High

The system shall provide reports on recurring lesson schedules, completion, cancellation, and payment status.

## **Learning Reports**

### **Learning Plan Report**

**Priority:** High

The system shall report on active, completed, and archived Learning Plans.

### **Homework Report**

**Priority:** Medium

The system shall report homework assignment, submission, overdue, and review metrics.

### **Curriculum Progress Report**

**Priority:** Medium

The system shall report curriculum, module, topic, and milestone progress where applicable.

## **Meeting Reports**

### **Meeting Report**

**Priority:** High

The system shall provide reports on meeting creation, attendance, failures, recordings, and technical issues.

### **No-Show Report**

**Priority:** High

The system shall report student and instructor no-show events.

## **Financial Reports**

### **Wallet Report**

**Priority:** Critical

The system shall provide wallet reports including balance liability, credits, debits, refunds, and adjustments.

### **Payment Report**

**Priority:** Critical

The system shall provide Razorpay payment reports including attempts, success, failure, and reconciliation issues.

### **Revenue Report**

**Priority:** Critical

The system shall provide revenue reports by date, subject, instructor, country, and booking type.

### **Instructor Earnings Report**

**Priority:** Critical

The system shall provide instructor earnings, settlement, and withdrawal reports.

### **Refund Report**

**Priority:** High

The system shall report wallet refund credits and related booking references.

## **Referral, Review & Communication Reports**

### **Referral Report**

**Priority:** High

The system shall report referral registrations, reward credits, campaign performance, and abuse flags.

### **Review & Quality Report**

**Priority:** High

The system shall report ratings, reviews, quality alerts, and instructor quality indicators.

### **Notification Delivery Report**

**Priority:** Medium

The system shall report notification delivery, failures, provider issues, and channel usage.

### **Messaging Report**

**Priority:** Medium

The system shall report controlled messaging usage, reported messages, and messaging restrictions.

## **Marketplace Analytics**

### **Search Analytics Report**

**Priority:** Medium

The system shall report search terms, filter usage, zero-result searches, and search conversion.

### **Profile View Report**

**Priority:** Medium

The system shall report instructor profile views and conversion from profile to booking.

### **Waitlist Report**

**Priority:** Medium

The system shall report waitlist demand by instructor, subject, and preferred time.

## **Filters & Export**

### **Report Filtering**

**Priority:** Critical

Reports shall support relevant filters such as date range, country, subject, instructor, student, status, and type.

### **CSV Export**

**Priority:** High

Authorized administrators shall be able to export permitted reports in CSV format.

### **Export Audit Log**

**Priority:** Critical

The system shall audit log sensitive report exports.

### **Permission-Based Report Access**

**Priority:** Critical

Report access shall be controlled by role and permission.

## **Data Freshness**

### **Data Freshness Indicator**

**Priority:** Medium

Reports shall indicate last updated time where data is not real-time.

### **KPI Snapshot**

**Priority:** Medium

The system shall support KPI snapshots for trend reporting and performance optimization.

# **19.36 Business Rules**

- Reports shall only display data authorized for the current user's role and permissions.
- Financial reports shall be restricted to authorized finance and super admin roles.
- Instructor compensation reports shall not be visible to unauthorized roles.
- Student personal information shall be minimized or masked in reports where possible.
- Sensitive exports shall be audit logged.
- Cancelled, failed, and refunded transactions must be clearly distinguished from successful transactions.
- Revenue reports shall distinguish between gross booking value, wallet recharge, refunds, credits, and instructor payouts.
- Review reports shall exclude hidden, rejected, or invalid reviews from public rating calculations.
- Report filters must not bypass permission restrictions.
- Dashboards should not expose confidential financial or personal information to unauthorized users.
- Version 1 reporting shall remain INR-first for financial reporting.
- Operational reports should prioritize current actionable items.

# **19.37 Validation Rules**

- Report date range must be valid.
- End date must not be earlier than start date.
- Report filters must reference valid active or historical entities.
- Export action requires export permission.
- Financial report access requires financial reporting permission.
- Export file must include report generation timestamp.
- Sensitive exports must include requesting admin reference in audit log.
- Report calculations must use defined status rules.
- Timezone-sensitive reports must clearly define reporting timezone.

# **19.38 User Workflows**

## **Admin Views Executive Dashboard**

- Admin logs into admin panel.
- Admin opens dashboard.
- System verifies permissions.
- System loads KPI cards.
- Admin applies date range.
- System updates charts and metrics.
- Admin reviews business performance.

## **Finance User Reviews Payment Report**

- Finance user opens payment report.
- System verifies finance report permission.
- User selects date range.
- System displays Razorpay payments and statuses.
- User filters failed or pending payments.
- User opens reconciliation issue where needed.
- User exports report if permitted.

## **Operations User Reviews Today's Lessons**

- Operations user opens operations dashboard.
- System displays today's lessons.
- User filters by status.
- User identifies no-show, meeting issue, or cancellation.
- User opens related booking.
- User takes operational action.

## **Marketplace Admin Reviews Search Performance**

- Marketplace admin opens search analytics.
- System displays top search terms.
- Admin reviews zero-result searches.
- Admin identifies subject demand gaps.
- Admin uses data for instructor recruitment or content planning.

## **Admin Exports Wallet Report**

- Admin opens wallet report.
- System verifies wallet report and export permission.
- Admin applies filters.
- Admin clicks export.
- System generates CSV.
- Export action is audit logged.
- Admin downloads report.

## **Academic Admin Reviews Learning Progress**

- Academic admin opens learning dashboard.
- System displays Learning Plan and homework metrics.
- Admin filters by subject and instructor.
- Admin identifies students with low engagement.
- Admin reviews related learning records.

# **19.39 Exception Handling**

## **Unauthorized Report Access**

The system shall deny access and record the attempt where appropriate.

## **Export Failure**

The system shall notify the user and allow retry where applicable.

## **Large Report Request**

The system may queue the export and notify the user when ready.

## **Data Calculation Error**

The system shall show a safe error message and notify administrators.

## **Missing Historical Entity**

Reports shall still display historical data even if related entity is archived, using stored snapshots where available.

## **Timezone Confusion**

Reports involving lesson times shall clearly indicate the timezone used.

# **19.40 Notifications**

Reporting-related notifications may include:

## **Administrator**

- Scheduled report ready, future
- Export completed
- Export failed
- Reconciliation issue detected
- Quality alert generated
- Payment mismatch found
- Meeting failure threshold exceeded
- Low wallet balance recurring report
- Suspicious referral pattern detected

# **19.41 Reports & Analytics Outputs**

Report outputs may include:

- KPI cards
- Tables
- Charts
- Trend lines
- Status summaries
- Distribution views
- Funnel views
- Conversion charts
- Export files
- Drill-down records

Version 1 may focus on KPI cards, tables, filters, and CSV exports.

# **19.42 Administrative Configuration**

Administrators shall be able to configure:

- Dashboard visibility by role
- Report access permissions
- Export permissions
- Default reporting timezone
- Default date ranges
- KPI definitions
- Report refresh behavior
- Export row limits
- Sensitive data masking rules
- Scheduled reports, future
- Report notification templates

# **19.43 Acceptance Criteria**

- Authorized administrators can view dashboards relevant to their role.
- Reports provide accurate data for students, instructors, bookings, meetings, wallet, payments, earnings, referrals, reviews, and notifications.
- Reports support date range and relevant domain filters.
- Financial reports are restricted to authorized users.
- Sensitive exports are permission-controlled and audit logged.
- Operational dashboards highlight actionable issues such as failed payments, no-shows, meeting failures, pending withdrawals, and support risks.
- Business dashboards show growth, conversion, retention, revenue, marketplace, and quality KPIs.
- CSV export is available for permitted reports.
- Reports distinguish between successful, failed, cancelled, refunded, and pending records.
- Reports preserve historical accuracy even when related users, instructors, subjects, or campaigns are archived.

# **19.44 Future Enhancements**

This module is designed to support future expansion, including:

- Custom report builder
- Scheduled email reports
- Excel exports
- PDF exports
- Real-time analytics dashboards
- BI warehouse integration
- Metabase or similar BI integration
- Cohort analysis
- Student lifetime value
- Churn prediction
- Instructor performance scoring
- AI-generated business insights
- AI anomaly detection
- Predictive revenue forecasting
- Advanced funnel analytics
- Marketing attribution
- Campaign ROI tracking
- Data warehouse and event streaming
- Role-specific dashboard personalization

# **19.45 Chapter Summary**

The Reports, Analytics & Business Intelligence module provides the visibility needed to operate and grow STEM Learning.

It brings together data from students, instructors, bookings, meetings, wallet, payments, earnings, referrals, reviews, notifications, marketplace discovery, and learning activity.

By separating operational reporting from business intelligence, the platform supports both daily management and long-term strategic decisions.

This module ensures that administrators can monitor platform health, track revenue, improve instructor quality, understand student behavior, evaluate marketing campaigns, and make informed decisions using accurate, permission-controlled, and exportable reports.

# **CHAPTER 20 - GLOBAL SETTINGS, CONFIGURATION & FEATURE CONTROL**

# **20.1 Introduction**

The Global Settings, Configuration & Feature Control module defines how STEM Learning administrators configure platform-wide business rules, operational behavior, feature availability, module policies, notification timings, booking rules, wallet rules, payment rules, referral rules, review rules, and future feature flags.

STEM Learning is designed as a configurable enterprise platform. Most operational rules should not be hard-coded. Administrators should be able to adjust platform behavior safely through controlled settings without requiring code changes for every business decision.

This module provides the configuration foundation for Booking, Availability, Wallet, Payments, Referrals, Reviews, Notifications, Instructor Settlement, Learning Plans, Marketplace, Meeting Management, and future modules.

# **20.2 Purpose**

The purpose of this module is to provide centralized, permission-controlled configuration management for the platform.

The module must ensure:

- Business rules are configurable.
- Settings changes are auditable.
- Sensitive settings are protected.
- Global defaults can be defined.
- Country-specific overrides can be supported where required.
- Feature availability can be controlled.
- Configuration changes do not break existing records.
- Admins can operate the platform without unnecessary developer dependency.

# **20.3 Business Objectives**

This module shall support the following business objectives:

- Reduce dependency on code changes for business policy updates.
- Support scalable platform administration.
- Enable fast business experimentation.
- Support region-specific operations.
- Control feature rollout safely.
- Maintain consistent business rules.
- Protect sensitive configuration.
- Improve operational flexibility.
- Support future international expansion.
- Maintain auditability of configuration changes.

# **20.4 Scope**

This chapter covers:

## **Global Settings**

- Platform identity
- Business contact information
- Default timezone
- Default currency
- Default language
- Legal page links
- Support contact settings

## **Module Settings**

- Booking settings
- Availability settings
- Meeting settings
- Wallet settings
- Payment settings
- Referral settings
- Review settings
- Notification settings
- Instructor settlement settings
- Learning settings

## **Feature Control**

- Feature enable/disable
- Module-level feature flags
- Country-level feature flags
- Role-based feature visibility
- Experimental features

## **Configuration Governance**

- Permission-based access
- Change history
- Audit logging
- Sensitive setting protection
- Validation
- Rollback readiness

# **20.5 Configuration Philosophy**

Configuration should follow three principles:

Configurable where business policy changes frequently.

Hard-coded only where system integrity requires it.

Audited wherever configuration affects users, money, bookings, or trust.

The platform should avoid two extremes:

- Too much hard-coding, which slows business changes.
- Too many uncontrolled settings, which creates operational risk.

Settings must be structured, validated, documented, and permission-controlled.

# **20.6 Configuration Layers**

The platform should support layered configuration.

## **Global Defaults**

Global defaults apply across the entire platform unless overridden.

Examples:

- Default lesson duration options
- Default cancellation policy
- Default wallet recharge limits
- Default notification reminders
- Default review window

## **Country Overrides**

Country overrides apply to users or operations in a specific country.

Examples:

- Country-specific minimum recharge amount
- Country-specific payment availability
- Country-specific tax behavior, future
- Country-specific support contact
- Country-specific timezone display
- Country-specific feature availability

## **Module-Specific Settings**

Module settings control behavior within a domain.

Examples:

- Booking reservation expiry
- Wallet low balance threshold
- Referral reward percentage
- Review moderation policy
- Instructor settlement delay

## **User Preference Settings**

User preferences control optional personal choices.

Examples:

- Notification preference
- Language preference
- Reminder preference
- Timezone preference

User preferences cannot override critical platform rules.

# **20.7 Settings Categories**

Settings should be organized into clear categories.

Recommended categories:

- Platform Settings
- Localization Settings
- Academic Settings
- Marketplace Settings
- Booking Settings
- Availability Settings
- Meeting Settings
- Wallet Settings
- Payment Settings
- Instructor Earnings Settings
- Referral & Promotional Settings
- Notification Settings
- Review & Quality Settings
- Security Settings
- Support Settings
- Feature Flags
- Reporting Settings

# **20.8 Platform Settings**

Platform settings define basic business identity and public-facing details.

Settings may include:

- Platform name
- Legal business name
- Public website URL
- Support email
- Support phone
- Business address
- Default sender email
- Default timezone
- Default language
- Default country
- Default currency
- Logo
- Favicon
- Social links
- Footer text

These settings may be used across emails, invoices, public website, dashboards, and notifications.

# **20.9 Academic Settings**

Academic settings control learning-related defaults.

Settings may include:

- Supported education levels
- Supported skill levels
- Default curriculum version behavior
- Learning Plan review frequency
- Homework due reminder timing
- Homework allowed file types
- Homework maximum file size
- Resource library limits
- Learning milestone display behavior
- Student progress visibility

Some academic entities are managed through Academic Framework modules, while settings define operational behavior.

# **20.10 Marketplace Settings**

Marketplace settings control discovery and public profile behavior.

Settings may include:

- Instructor public profile enabled/disabled
- Featured instructor section enabled/disabled
- New instructor visibility boost enabled/disabled
- Recently viewed instructors enabled/disabled
- Favorites enabled/disabled
- Waitlist enabled/disabled
- Default marketplace sort
- Minimum instructor profile completion percentage
- Public SEO indexing enabled/disabled
- Profile view analytics enabled/disabled

# **20.11 Booking Settings**

Booking settings control lesson reservation and lifecycle behavior.

Settings may include:

- Demo booking enabled/disabled
- Paid booking enabled/disabled
- Recurring booking enabled/disabled
- Supported lesson durations
- Default lesson duration
- Demo lesson duration
- Slot reservation expiry time
- Minimum booking notice
- Maximum advance booking window
- Cancellation window
- Reschedule window
- Maximum reschedules allowed
- Auto-completion delay
- No-show grace period
- Technical issue reporting window
- Booking reference format

These settings are high-impact because they affect revenue and user experience.

# **20.12 Availability Settings**

Availability settings control how instructor availability is generated and displayed.

Settings may include:

- Default buffer time
- Minimum instructor weekly availability, optional
- Maximum daily teaching hours, optional
- Vacation mode enabled/disabled
- Waitlist integration enabled/disabled
- Public holiday behavior, future
- Earliest available slot display
- Availability calendar range
- Instructor availability update restrictions
- Booking conflict enforcement

# **20.13 Meeting Settings**

Meeting settings control virtual classroom behavior.

Settings may include:

- Active meeting provider
- Platform meeting account
- Meeting creation timing
- Meeting link visibility window
- Admin observer enabled/disabled
- Recording enabled/disabled
- Recording storage provider
- Recording retention period
- Attendance tracking enabled/disabled
- Meeting failure retry attempts
- Technical issue reporting enabled/disabled
- Meeting notification timing

Sensitive provider credentials must not be exposed in normal admin interfaces.

# **20.14 Wallet Settings**

Wallet settings control student wallet behavior.

Settings may include:

- Wallet enabled/disabled
- Minimum recharge amount
- Maximum recharge amount
- Suggested recharge amounts
- Low balance threshold
- Recurring deduction timing
- Recurring payment cutoff
- Wallet refund enabled
- Admin manual credit enabled
- Admin manual debit enabled
- Wallet transaction reference format
- Wallet statement visibility
- Promotional credit enabled/disabled

Wallet settings are financially sensitive and require strict permissions.

# **20.15 Payment Settings**

Payment settings control external payment behavior.

For Version 1, payment settings are Razorpay-focused.

Settings may include:

- Razorpay enabled/disabled
- Razorpay mode
- Checkout expiry
- Payment retry allowed
- Direct booking payment enabled/disabled
- Wallet recharge payment enabled/disabled
- Invoice generation enabled/disabled
- Invoice numbering format
- Receipt template
- Payment failure message
- Reconciliation alert thresholds

Sensitive keys and secrets must be stored securely and not exposed casually.

# **20.16 Instructor Earnings Settings**

Instructor earnings settings control pay, settlement, and withdrawals.

Settings may include:

- Instructor earnings enabled/disabled
- Default pay model
- Settlement delay
- Settlement cycle
- Minimum withdrawal amount
- Withdrawal methods enabled
- Demo compensation policy
- Demo-to-paid incentive enabled/disabled
- Instructor no-show earning rule
- Student no-show compensation rule
- Manual earning adjustment permissions
- Withdrawal approval required
- Payout statement enabled/disabled

Financial configuration changes must be audit logged.

# **20.17 Referral & Promotional Settings**

Referral settings control student referrals and wallet rewards.

Settings may include:

- Referral program enabled/disabled
- Referral code format
- Reward type
- Reward value
- Maximum rewarded classes
- Minimum paid lesson requirement
- Reward timing
- Fraud review requirement
- Promotional credit enabled/disabled
- Manual promotional credit permissions
- Campaign budget cap, future
- Referral notification enabled/disabled

Recommended Version 1 defaults:

- Student referral only
- Wallet credit only
- Reward after paid lesson completion
- Per-class reward supported
- Maximum 10 rewarded classes
- Suggested 5% per eligible class, configurable

# **20.18 Notification Settings**

Notification settings control communication behavior.

Settings may include:

- Email enabled/disabled
- SMS enabled/disabled
- WhatsApp enabled/disabled
- In-app notifications enabled/disabled
- Lesson reminder schedule
- Homework reminder schedule
- Low wallet reminder schedule
- Booking confirmation channels
- Payment notification channels
- Notification retry rules
- Critical notification rules
- Marketing notification settings
- Provider failure thresholds

Critical transactional notifications should not be disabled casually.

# **20.19 Review & Quality Settings**

Review settings control review eligibility, moderation, and quality alerts.

Settings may include:

- Reviews enabled/disabled
- Paid lesson review enabled/disabled
- Demo review policy
- Review window
- Rating scale
- Rating dimensions
- Written review required/optional
- Review moderation mode
- Report reasons
- Student name display format
- Instructor response enabled/disabled
- Quality alert thresholds
- Low rating threshold
- Rating aggregation behavior

# **20.20 Security Settings**

Security settings control platform protection.

Settings may include:

- Password policy
- Login attempt limits
- Session timeout
- Email verification required
- Instructor approval required
- Sensitive action confirmation
- Admin IP restriction, future
- Two-factor authentication, future
- Account suspension rules
- Data export restrictions
- File upload restrictions

Some security settings should be editable only by super administrators.

# **20.21 Support Settings**

Support settings control support and operational communication.

Settings may include:

- Support email
- Support phone
- WhatsApp support number
- Support hours
- Help center link
- Technical issue reporting window
- Escalation recipients
- Refund support category
- Meeting issue category
- Default support response templates, future

# **20.22 Reporting Settings**

Reporting settings control report behavior.

Settings may include:

- Default reporting timezone
- Default date range
- Export row limit
- Sensitive export audit enabled
- Financial report access roles
- KPI refresh interval
- Dashboard visibility by role
- Report cache duration
- Scheduled reports, future

# **20.23 Feature Flags**

Feature flags allow administrators to enable or disable specific features safely.

Examples:

- Free demo booking
- Recurring booking
- Wallet recharge
- Referral program
- Instructor chat
- Admin observer
- Recording
- Reviews
- Homework
- Public instructor profiles
- Waitlist
- AI features, future

Feature flags may apply globally, by country, by role, or by user segment.

# **20.24 Feature Rollout Strategy**

Feature rollout may follow stages:

Disabled

│

▼

Internal Testing

│

▼

Limited Beta

│

▼

Country Rollout

│

▼

Global Enabled

This allows safe introduction of new features.

# **20.25 Experimental Features**

Experimental features should be clearly marked.

Examples:

- AI recommendation
- AI homework generation
- AI review moderation
- Smart scheduling
- Auto top-up
- Instructor response to reviews
- Saved payment methods

Experimental features should not affect financial or critical workflows without additional controls.

# **20.26 Settings Change Governance**

Settings changes must follow governance rules.

High-impact settings include:

- Payment settings
- Wallet settings
- Refund rules
- Instructor pay settings
- Settlement delay
- Cancellation rules
- Meeting provider
- Security settings
- Referral reward value
- Review moderation policy

High-impact settings changes should require:

- Proper permission
- Validation
- Confirmation
- Reason, where applicable
- Audit log
- Change history

# **20.27 Settings Versioning**

The system should preserve change history for important settings.

Settings history may include:

- Previous value
- New value
- Changed by
- Changed at
- Reason
- Module
- Impact level

Historical business records should continue using the values captured at the time of transaction where required.

Example:

- Booking cancellation policy changes should not alter historical cancellation outcomes.
- Instructor pay rule changes should not alter historical earnings.
- Referral reward changes should not alter already credited rewards.

# **20.28 Setting Snapshots**

Certain workflows should store setting snapshots.

Examples:

- Booking stores cancellation and refund policy snapshot.
- Instructor earning stores pay rule snapshot.
- Referral reward stores campaign rule snapshot.
- Wallet transaction stores transaction context.
- Payment stores amount and currency snapshot.
- Review stores moderation policy state if needed.

Snapshots protect historical accuracy.

# **20.29 Sensitive Settings**

Sensitive settings require extra protection.

Examples:

- Payment gateway credentials
- Meeting provider credentials
- Email provider API keys
- SMS provider credentials
- WhatsApp provider credentials
- Security rules
- Financial limits
- Admin role permissions

Sensitive values should be masked in the interface and not exposed after saving.

# **20.30 Permission Model for Settings**

Settings access should be role-based.

Examples:

- Super Admin: all settings
- Finance Admin: wallet, payment, settlement, invoice settings
- Academic Admin: academic and learning settings
- Marketplace Admin: marketplace and profile settings
- Operations Admin: booking, availability, meeting settings
- Communication Admin: notification templates and communication settings
- Support Admin: support-related settings

No role should have more access than required.

# **20.31 Configuration Validation**

The system must validate settings before saving.

Examples:

- Minimum recharge must be less than maximum recharge.
- Demo duration must be one of supported durations.
- Cancellation window cannot be negative.
- Settlement delay must be non-negative.
- Review window must be positive.
- Referral percentage must be within allowed range.
- Recording retention must be valid.
- Payment gateway cannot be enabled without required configuration.

# **20.32 Configuration Dependencies**

Some settings depend on other settings.

Examples:

- Recurring booking requires wallet or recurring payment configuration.
- Meeting recording requires active meeting provider and storage.
- WhatsApp notifications require WhatsApp provider enabled.
- Referral rewards require wallet enabled.
- Wallet recharge requires payment gateway enabled.
- Public instructor profiles require approved instructor workflow.
- Reviews require completed lesson workflow.

The system should detect and communicate dependency issues.

# **20.33 Safe Defaults**

The platform should ship with safe default settings.

Examples:

- Demo booking enabled only if configured.
- Wallet enabled for students.
- Refunds credited to wallet only.
- Instructor pay hidden from students.
- Student price hidden from instructors.
- Meeting links restricted.
- Reviews only after completed paid lessons.
- Referral rewards after paid lesson completion.
- Sensitive exports audit logged.
- Critical notifications enabled.

# **20.34 Settings Import and Export**

Future versions may support settings import/export for backup or environment replication.

Version 1 may provide manual configuration only.

If export is supported, sensitive values must be excluded or masked.

# **20.35 Admin Settings Interface**

The settings interface should be organized by module.

Recommended interface structure:

- General
- Academic
- Marketplace
- Booking
- Availability
- Meeting
- Wallet
- Payment
- Instructor Earnings
- Referral
- Notifications
- Reviews
- Security
- Support
- Reports
- Feature Flags

Each section should show clear descriptions and validation messages.

# **20.36 Functional Requirements**

## **General Settings**

### **Platform Settings Management**

**Priority:** Critical

The system shall allow authorized administrators to manage general platform settings.

### **Business Identity Settings**

**Priority:** High

The system shall allow administrators to configure business name, support contact, website URL, logo, and public identity details.

### **Default Locale Settings**

**Priority:** High

The system shall allow administrators to configure default country, timezone, currency, and language.

## **Module Settings**

### **Academic Settings**

**Priority:** Medium

The system shall allow authorized administrators to configure academic behavior settings.

### **Marketplace Settings**

**Priority:** High

The system shall allow authorized administrators to configure marketplace discovery and public profile settings.

### **Booking Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure booking, cancellation, rescheduling, and no-show settings.

### **Availability Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure availability, buffer, vacation, and waitlist settings.

### **Meeting Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure meeting provider, access window, recording, and observer settings.

### **Wallet Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure wallet behavior, recharge limits, low balance rules, and refund behavior.

### **Payment Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure Razorpay payment behavior and checkout settings.

### **Instructor Earnings Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure instructor pay, settlement, withdrawal, and incentive settings.

### **Referral Settings**

**Priority:** High

The system shall allow authorized administrators to configure referral reward and promotional credit settings.

### **Notification Settings**

**Priority:** High

The system shall allow authorized administrators to configure notification channels, reminders, and retry behavior.

### **Review Settings**

**Priority:** High

The system shall allow authorized administrators to configure review eligibility, rating, moderation, and quality alert settings.

### **Security Settings**

**Priority:** Critical

The system shall allow authorized administrators to configure security-related settings according to permission policy.

## **Feature Control**

### **Feature Flag Management**

**Priority:** High

The system shall allow authorized administrators to enable or disable platform features.

### **Country-Level Feature Control**

**Priority:** Medium

The system shall support enabling or disabling selected features by country.

### **Role-Based Feature Control**

**Priority:** Medium

The system shall support feature visibility based on user role where applicable.

### **Experimental Feature Marking**

**Priority:** Low

The system shall allow experimental features to be clearly marked and controlled.

## **Validation & Dependencies**

### **Settings Validation**

**Priority:** Critical

The system shall validate settings before saving.

### **Dependency Validation**

**Priority:** High

The system shall detect missing configuration dependencies before enabling related features.

### **Safe Default Configuration**

**Priority:** High

The system shall provide safe default settings for core modules.

## **Governance**

### **Settings Permission Control**

**Priority:** Critical

Settings access shall be controlled by role and permission.

### **Settings Change Audit Log**

**Priority:** Critical

The system shall audit log all settings changes.

### **Settings Change History**

**Priority:** High

The system shall preserve previous and new values for important settings.

### **Sensitive Setting Masking**

**Priority:** Critical

Sensitive configuration values shall be masked and protected.

### **High-Impact Change Confirmation**

**Priority:** High

The system shall require confirmation for high-impact setting changes.

### **Settings Change Reason**

**Priority:** Medium

The system may require reason for high-impact configuration changes.

## **Snapshots**

### **Business Rule Snapshot**

**Priority:** High

The system shall store configuration snapshots for workflows where historical accuracy is required.

### **Historical Rule Preservation**

**Priority:** Critical

Historical records shall not be silently recalculated when settings change unless explicitly designed.

## **Interface**

### **Organized Settings Interface**

**Priority:** High

The system shall organize settings by module and business area.

### **Settings Description**

**Priority:** Medium

Settings fields should include clear descriptions explaining business impact.

### **Test Configuration**

**Priority:** Medium

The system may allow authorized administrators to test selected provider configurations such as email or payment where safe.

# **20.37 Business Rules**

- Business policies that change frequently should be configurable.
- Critical system integrity rules may remain non-configurable.
- Settings changes must be permission-controlled.
- High-impact settings changes must be audit logged.
- Sensitive credentials must not be exposed after saving.
- Financial settings may only be modified by authorized roles.
- Security settings may only be modified by super administrator or equivalent authorized role.
- Feature flags must not bypass core eligibility, payment, security, or audit rules.
- Historical transactions shall preserve the business rules active when they were created where applicable.
- Referral reward changes shall not modify already credited rewards.
- Instructor pay rule changes shall not modify historical earnings.
- Payment settings must be valid before payment collection is enabled.
- Wallet recharge requires a valid enabled payment configuration.
- Meeting recording requires valid provider and storage configuration.
- Critical transactional notifications shall not be disabled casually.

# **20.38 Validation Rules**

- Numeric setting values must be within allowed range.
- Date or duration settings must be valid and non-negative where applicable.
- Minimum value must not exceed maximum value.
- Lesson duration values must match supported platform durations.
- Percentage values must be within configured percentage limits.
- Payment gateway cannot be enabled without required credentials.
- Wallet recharge cannot be enabled if payment gateway is disabled.
- Referral reward cannot be enabled if wallet is disabled.
- Review window must be greater than zero when reviews are enabled.
- Settlement delay must be greater than or equal to zero.
- Configuration keys must be unique within their scope.
- Sensitive values must not be displayed in plain text after saving.

# **20.39 User Workflows**

## **Admin Updates Booking Settings**

- Admin opens Booking Settings.
- System verifies permission.
- Admin updates cancellation window or reservation expiry.
- System validates values.
- System identifies business impact.
- Admin confirms change.
- System saves setting.
- Change is audit logged.
- Future bookings use updated setting.

## **Finance Admin Updates Wallet Recharge Limit**

- Finance admin opens Wallet Settings.
- System verifies financial setting permission.
- Admin updates minimum or maximum recharge amount.
- System validates numeric limits.
- Admin provides reason if required.
- System saves setting.
- Change history is recorded.
- Future wallet recharges use updated limits.

## **Admin Enables Referral Program**

- Admin opens Referral Settings.
- Admin enables referral program.
- System checks wallet dependency.
- Admin configures reward type, value, and maximum rewarded classes.
- System validates campaign settings.
- Admin saves settings.
- Referral feature becomes available.

## **Admin Enables Meeting Recording**

- Admin opens Meeting Settings.
- Admin enables recording.
- System checks active meeting provider.
- System checks storage configuration.
- Admin configures retention period.
- System validates settings.
- Recording feature is enabled.
- Change is audit logged.

## **Admin Updates Notification Template Timing**

- Admin opens Notification Settings.
- Admin updates lesson reminder timing.
- System validates timing.
- System saves reminder schedule.
- Future reminders use updated timing.
- Change is logged.

## **Super Admin Updates Security Setting**

- Super Admin opens Security Settings.
- System verifies highest-level permission.
- Super Admin updates session timeout or login attempt rule.
- System validates setting.
- Super Admin confirms high-impact change.
- System saves setting.
- Audit log is recorded.

# **20.40 Exception Handling**

## **Invalid Setting Value**

The system shall reject the change and display a clear validation message.

## **Missing Dependency**

The system shall prevent enabling a feature and explain the missing dependency.

Example:

Referral rewards cannot be enabled because wallet is disabled.

## **Unauthorized Settings Access**

The system shall deny access and may log the attempt.

## **Sensitive Setting Display Attempt**

The system shall mask sensitive values and prevent unauthorized viewing.

## **Configuration Save Failure**

The system shall preserve the previous setting and notify the administrator.

## **Feature Enabled but Provider Fails**

The system shall alert administrators and may automatically disable or mark the feature as degraded depending on policy.

## **Historical Rule Conflict**

The system shall preserve historical records and apply new settings only to future workflows unless explicitly supported.

# **20.41 Notifications**

Settings-related notifications may include:

## **Administrator**

- High-impact setting changed
- Payment configuration updated
- Wallet limits changed
- Meeting provider changed
- Security setting changed
- Feature enabled or disabled
- Provider test failed
- Configuration dependency issue detected

## **Super Admin**

- Sensitive setting changed
- Financial setting changed
- Security setting changed
- Export setting changed
- Feature flag modified

Settings change notifications should be configurable but high-risk changes should notify authorized leadership roles.

# **20.42 Reports & Audit Outputs**

Settings reporting may include:

- Settings change history
- High-impact changes
- Financial setting changes
- Security setting changes
- Feature flag changes
- Provider configuration status
- Last changed by
- Last changed at
- Current feature availability
- Country-level override list

# **20.43 Administrative Configuration**

Since this chapter itself governs configuration, meta-configuration should be limited.

The system may allow:

- Which roles can manage settings
- Which settings require reason
- Which settings require confirmation
- Which settings trigger notifications
- Which settings are considered high-impact
- Which settings are exportable
- Which settings are masked
- Which settings allow country overrides

# **20.44 Acceptance Criteria**

- Authorized administrators can manage global platform settings.
- Settings are organized by module and business function.
- Financial, payment, wallet, security, and meeting provider settings are permission-controlled.
- Invalid setting combinations are rejected before saving.
- Feature flags can enable or disable selected platform features.
- Country-specific overrides can be supported for selected settings where applicable.
- High-impact setting changes are audit logged with previous and new values.
- Sensitive configuration values are masked and protected.
- Historical business records preserve the rule context active when they were created where required.
- Configuration dependencies are clearly communicated to administrators.

# **20.45 Future Enhancements**

This module is designed to support future expansion, including:

- Approval workflow for critical setting changes
- Scheduled configuration changes
- Settings rollback
- Configuration versioning
- Country-specific override builder
- Environment-specific settings sync
- Settings import/export
- Advanced feature rollout management
- User-segment-based feature flags
- A/B testing
- Experiment management
- Configuration health dashboard
- Automated dependency checker
- AI-assisted configuration recommendations
- Multi-brand platform configuration
- Multi-tenant configuration, if business expands

# **20.46 Chapter Summary**

The Global Settings, Configuration & Feature Control module is the control center of STEM Learning.

It ensures that business policies, platform behavior, module rules, financial limits, communication settings, review policies, booking rules, referral rewards, meeting behavior, and feature availability can be safely managed without unnecessary code changes.

By supporting global defaults, country overrides, module-level settings, feature flags, validation, dependency checks, permissions, audit logs, and sensitive setting protection, the platform remains flexible while preserving operational safety.

This module allows STEM Learning to adapt quickly as the business grows, while keeping financial, security, and user-impacting configuration under strict governance.

# **STEM Learning**

## **Enterprise Software Requirements Specification (SRS) v2.0**

# **BOOK 2 - FUNCTIONAL REQUIREMENTS**

# **PART F - PLATFORM ADMINISTRATION & OPERATIONS**

# **CHAPTER 21 - COUNTRY, CURRENCY, LOCALIZATION & REGIONAL OPERATIONS**

# **21.1 Introduction**

The Country, Currency, Localization & Regional Operations module defines how STEM Learning manages country-specific platform behavior, currency display, timezone handling, regional availability, localized user experience, country-based rules, and future international expansion.

Although STEM Learning may serve students globally in the future, Version 1 shall remain operationally India-first, with Razorpay as the primary payment gateway and INR as the primary settlement and reporting currency.

This module ensures that the platform can support different countries, currencies, timezones, languages, pricing rules, notification formats, legal requirements, and operational policies without redesigning the system later.

# **21.2 Purpose**

The purpose of this module is to provide a structured regional operating model for STEM Learning.

The module must ensure:

- Countries can be managed by administrators.
- Each country can define operational defaults.
- INR remains the primary Version 1 settlement currency.
- Timezone display is accurate for students and instructors.
- Date, time, currency, and language formatting are localized.
- Country-specific settings can override global defaults.
- Future international expansion is supported safely.
- Regional business rules are auditable and configurable.

# **21.3 Business Objectives**

This module shall support the following business objectives:

- Operate India-first with INR and Razorpay in Version 1.
- Prepare the platform for future US/UK/global expansion.
- Support country-specific user experience.
- Prevent timezone confusion in global lessons.
- Support localized price display where enabled.
- Support regional subject availability.
- Support country-level feature controls.
- Enable country-wise reporting and analytics.
- Support future payment gateway expansion.
- Reduce future rework when expanding internationally.

# **21.4 Scope**

This chapter covers:

## **Country Management**

- Country master
- Country status
- Default currency
- Default timezone
- Default language
- Country availability
- Regional business rules

## **Currency Management**

- INR-first Version 1 strategy
- Currency master
- Display currency
- Settlement currency
- Reporting currency
- Future multi-currency support

## **Localization**

- Language readiness
- Date format
- Time format
- Currency format
- Timezone display
- Regional content

## **Regional Operations**

- Country-specific settings
- Regional subject availability
- Country-specific pricing readiness
- Country-specific payment readiness
- Country-wise reports
- Regional feature flags

# **21.5 Regional Strategy**

STEM Learning shall follow a phased regional strategy.

## **Version 1**

India-first operating model:

- Company based in India
- Razorpay as primary payment gateway
- INR as primary settlement currency
- INR-first wallet and financial reports
- English-first interface
- Country-aware structure prepared for future

## **Future Expansion**

The platform may later support:

- United States
- United Kingdom
- Canada
- Australia
- Middle East
- Europe
- Other regions

Future expansion may require:

- Country-specific pricing
- Local payment gateways
- Multi-currency wallets
- Tax rules
- Local legal pages
- Local support channels
- Regional marketing content

# **21.6 Country Master**

The platform shall maintain a Country Master.

Each country record may include:

- Country name
- ISO country code
- Country status
- Default currency
- Default timezone
- Supported languages
- Phone code
- Date format
- Time format
- Number format
- Payment enabled/disabled
- Wallet enabled/disabled
- Booking enabled/disabled
- Public marketplace enabled/disabled
- Support contact
- Legal region notes, future

Example:

| **Country**    | **Code** | **Currency** | **Timezone**  | **Status** |
| -------------- | -------- | ------------ | ------------- | ---------- |
| India          | IN       | INR          | Asia/Kolkata  | Active     |
| ---            | ---      | ---          | ---           | ---        |
| United States  | US       | USD          | Multiple      | Future     |
| ---            | ---      | ---          | ---           | ---        |
| United Kingdom | GB       | GBP          | Europe/London | Future     |
| ---            | ---      | ---          | ---           | ---        |
| Canada         | CA       | CAD          | Multiple      | Future     |
| ---            | ---      | ---          | ---           | ---        |
| Australia      | AU       | AUD          | Multiple      | Future     |
| ---            | ---      | ---          | ---           | ---        |

# **21.7 Country Status**

Countries may have statuses.

## **Active**

Country is available for platform operations.

## **Inactive**

Country is configured but not currently available.

## **Future**

Country is planned for future rollout.

## **Restricted**

Country is not available due to business, legal, payment, or operational constraints.

## **Archived**

Country is no longer active but preserved for historical reporting.

Only active countries should be available for new user onboarding unless admin override is permitted.

# **21.8 Country Availability**

Country availability controls whether users from a country can:

- Register
- Browse public marketplace
- Book lessons
- Recharge wallet
- Make payments
- Receive localized communication
- Access country-specific pricing
- Use referral campaigns
- Access specific features

Version 1 may keep India active and other countries future/inactive until expansion is ready.

# **21.9 Currency Strategy**

The platform shall distinguish between different currency concepts.

## **Display Currency**

The currency shown to the user.

Example:

- Student sees ₹ for India.
- Future US student may see \$.

## **Payment Currency**

The currency used for actual payment collection.

Version 1:

- INR via Razorpay.

## **Wallet Currency**

The currency used inside student wallet.

Version 1:

- INR.

## **Settlement Currency**

The currency in which the company receives settlement.

Version 1:

- INR.

## **Reporting Currency**

The primary currency used for business reports.

Version 1:

- INR.

This separation prepares the system for future internationalization.

# **21.10 INR-First Version 1 Rule**

For Version 1, STEM Learning shall operate with INR as the primary financial currency.

Rules:

- Razorpay is the primary gateway.
- Wallet recharge operates in INR.
- Student wallet operates in INR.
- Instructor earnings operate in INR.
- Instructor withdrawals operate in INR.
- Financial reports are INR-first.
- Refunds are credited to wallet in INR.
- Referral rewards are credited to wallet in INR.

Future multi-currency behavior shall not affect Version 1 financial simplicity.

# **21.11 Multi-Currency Future Readiness**

Although Version 1 is INR-first, the data model should be future-ready.

Future multi-currency support may include:

- Student display currency by country.
- Wallet currency by billing country.
- Payment currency by country.
- Gateway routing by country.
- Exchange rate snapshots.
- Multi-currency reports.
- Instructor payout currency by country.
- Regional tax behavior.

Future currency conversion must store snapshots so historical transactions remain accurate.

# **21.12 Exchange Rate Handling**

Exchange rate handling is not required for Version 1 core operations.

Future exchange rate functionality may be used for:

- Reporting conversion
- Display estimates
- Internal financial summaries
- Cross-country revenue comparison
- Instructor payout conversion

Future exchange rate rules:

- Exchange rate must be stored with transaction snapshot.
- Historical records must not change when exchange rates change.
- Exchange rate source must be auditable.
- Display conversion should be clearly marked if approximate.

# **21.13 Timezone Strategy**

Timezone handling is critical for online learning.

Core rules:

- Instructor defines availability in instructor local timezone.
- Student views lesson times in student local timezone.
- Confirmed booking times are stored in UTC.
- Notifications display time in recipient timezone.
- Admin reports may use configurable reporting timezone.
- Meeting provider receives accurate scheduled time.
- Calendar invites include correct timezone context.

Timezone confusion must be minimized across the platform.

# **21.14 User Timezone**

The platform shall maintain user timezone.

Timezone may be determined by:

- Country default
- User selection
- Browser/device detection, future
- Admin update, where permitted

Users should be able to confirm or update their timezone where appropriate.

This is especially important for students booking instructors in other countries.

# **21.15 Country Default Timezone**

Some countries have one timezone.

Example:

- India: Asia/Kolkata
- United Kingdom: Europe/London

Some countries have multiple timezones.

Example:

- United States
- Canada
- Australia

For multi-timezone countries, the system should allow user-specific timezone selection rather than relying only on country default.

# **21.16 Daylight Saving Time**

The system shall support daylight saving time where applicable.

Rules:

- Store confirmed lesson times in UTC.
- Display lesson times in local timezone.
- Recurring schedules should respect instructor local clock time.
- Students should see converted time accurately.
- Notifications should use the recipient's correct local time.

India does not use daylight saving time, but future US/UK expansion requires proper handling.

# **21.17 Localization Strategy**

Localization includes adapting the user experience for region and language.

Localization may include:

- Language
- Currency symbol
- Date format
- Time format
- Number format
- Phone number format
- Country-specific content
- Legal page links
- Notification templates
- Support contact
- Regional terminology

Version 1 may use English-first content while keeping localization-ready structure.

# **21.18 Language Strategy**

Version 1 recommended approach:

- English as default interface language.
- English notification templates.
- Future support for additional languages.

Future languages may include:

- Hindi
- Spanish
- Arabic
- French
- German
- Other regional languages based on business expansion

Language support should be prepared at the content/template level.

# **21.19 Date and Time Formatting**

The platform shall display date and time using regional settings.

Examples:

India:

04 July 2026, 7:00 PM

United States, future:

July 4, 2026, 7:00 PM

United Kingdom, future:

4 July 2026, 19:00

The system should avoid ambiguous formats such as 04/07/2026 when users may come from multiple regions.

# **21.20 Currency Formatting**

The platform shall display money using appropriate currency formatting.

Examples:

India:

₹1,500

United States, future:

\$25

United Kingdom, future:

£20

Version 1 shall primarily use INR formatting.

# **21.21 Phone Number Localization**

Phone number handling should support country codes.

Examples:

- India: +91
- United States: +1
- United Kingdom: +44
- Canada: +1
- Australia: +61

Phone validation may depend on country.

Version 1 may focus on India phone validation with future international readiness.

# **21.22 Country-Specific Pricing**

Country-specific pricing was defined conceptually in earlier chapters.

For Version 1, because Razorpay and INR are primary, pricing may remain INR-first.

Future country pricing may support:

- Subject price by country
- Education level price by country
- Lesson duration price by country
- Regional promotional pricing
- Country-specific taxes
- Local payment currency

Important rule:

Student-facing pricing and instructor compensation remain separate.

Country pricing must never expose instructor pay or platform margin.

# **21.23 Regional Subject Availability**

Subjects may be available in selected countries.

Examples:

- Mathematics: Global
- English Conversation: Global
- GCSE Maths: UK-focused, future
- SAT Prep: US-focused, future
- CBSE Mathematics: India-focused, future
- Coding for Kids: Global

Administrators should be able to control where a subject or curriculum is visible.

# **21.24 Regional Instructor Availability**

Instructor marketplace visibility may depend on:

- Instructor status
- Subjects taught
- Teaching language
- Student country
- Timezone compatibility
- Country availability rules
- Payment availability
- Legal or operational restrictions

Future region-specific marketplace rules may prioritize instructors based on student country.

# **21.25 Regional Feature Flags**

Some features may be enabled by country.

Examples:

- Wallet recharge
- Referral program
- Reviews
- Demo booking
- Recurring booking
- WhatsApp notifications
- Payment gateway
- Recording
- AI features, future
- Country-specific legal consent

Country-level feature flags should not bypass core eligibility, safety, or payment rules.

# **21.26 Regional Payment Operations**

Version 1:

- Razorpay only.
- INR payment collection.
- India-first settlement.

Future regional payment operations may include:

- Stripe for US/UK
- Local payment gateways
- International cards
- Multi-currency payment collection
- Country-specific payment availability
- Local tax invoice support

Future gateway expansion should be controlled through country/payment settings.

# **21.27 Regional Wallet Operations**

Version 1:

- INR wallet.
- Wallet recharge through Razorpay.
- Wallet refunds in INR.
- Referral rewards in INR.
- Promotional credits in INR.

Future regional wallet operations may include:

- Separate wallet currency per country.
- Multi-currency wallet restrictions.
- Currency conversion snapshots.
- Country-specific recharge limits.
- Country-specific refund rules.

# **21.28 Regional Instructor Payouts**

Version 1:

- Instructor earnings and withdrawals are INR-first.

Future regional instructor payouts may include:

- Instructor payout country
- Payout currency
- Local bank details
- Payment method by country
- Tax details by country
- Compliance checks

Instructor payout expansion should be handled carefully and not mixed with student pricing logic.

# **21.29 Regional Legal Pages**

Legal content may vary by region.

Examples:

- Terms & Conditions
- Privacy Policy
- Refund Policy
- Cookie Policy
- Recording Consent
- Instructor Agreement
- Student Agreement
- Data Retention Policy

Version 1 may use India-based legal pages with future country-specific variants.

The CMS should support regional legal page assignment in future.

# **21.30 Regional Notification Templates**

Notifications may vary by country or language.

Examples:

- Currency formatting
- Timezone display
- Support phone number
- Legal footer
- WhatsApp availability
- Localized wording

Version 1 may use English templates with India contact/support information.

# **21.31 Regional Support Operations**

Support information may be country-specific.

Settings may include:

- Support email
- Support phone
- WhatsApp support number
- Support hours
- Escalation contact
- Refund support process
- Meeting issue process

Version 1 support may be India-based while serving early users.

# **21.32 Regional Reporting**

Reports shall support country filters where applicable.

Examples:

- Students by country
- Bookings by country
- Revenue by country
- Subject demand by country
- Referral performance by country
- Instructor availability by country
- Review trends by country
- Payment success by country, future
- Wallet activity by country, future

Version 1 financial reporting remains INR-first.

# **21.33 Regional SEO and Public Pages**

Future regional SEO may include:

- Country-specific landing pages
- Subject pages by country
- Instructor profiles optimized by region
- Localized meta titles
- Localized meta descriptions
- Country-specific FAQs
- Region-specific testimonials
- Country-specific pricing pages

Version 1 should preserve URL and CMS readiness for future regional pages.

# **21.34 Regional Content Visibility**

CMS content may be shown or hidden by country in future.

Examples:

- Homepage hero
- Promotions
- FAQs
- Legal content
- Subject landing pages
- Pricing explanation
- Blog content
- Banner campaigns

This allows regional marketing without separate platforms.

# **21.35 Country-Level Administrative Controls**

Administrators should be able to manage:

- Country status
- Country currency
- Country timezone
- Country language
- Country feature availability
- Country pricing readiness
- Country support details
- Country legal content
- Country reporting visibility

Changes should be audit logged.

# **21.36 Functional Requirements**

## **Country Management**

### **Country Master Management**

**Priority:** Critical

The system shall allow authorized administrators to manage country records.

### **Country Status**

**Priority:** High

The system shall support country statuses such as active, inactive, future, restricted, and archived.

### **Country Defaults**

**Priority:** High

The system shall allow each country to define default currency, timezone, language, phone code, and formatting preferences.

### **Country Availability**

**Priority:** High

The system shall control whether registration, booking, payment, wallet, and marketplace features are available for each country.

## **Currency Management**

### **Currency Master**

**Priority:** High

The system shall maintain currency records including currency code, symbol, precision, and status.

### **INR Primary Currency**

**Priority:** Critical

Version 1 shall use INR as the primary settlement, wallet, instructor earning, and reporting currency.

### **Currency Formatting**

**Priority:** High

The system shall display currency values using the applicable currency symbol and formatting rules.

### **Future Multi-Currency Readiness**

**Priority:** Medium

The system design shall allow future support for display currency, payment currency, wallet currency, and reporting currency separation.

## **Timezone**

### **User Timezone**

**Priority:** Critical

The system shall store and use user timezone for students and instructors.

### **UTC Booking Storage**

**Priority:** Critical

Confirmed booking times shall be stored in UTC.

### **Local Time Display**

**Priority:** Critical

The system shall display lesson times in the recipient's local timezone.

### **Timezone Selection**

**Priority:** High

Users shall be able to confirm or update timezone where applicable.

### **Daylight Saving Support**

**Priority:** High

The system shall support daylight saving time for future applicable countries.

## **Localization**

### **Locale Formatting**

**Priority:** High

The system shall support localized date, time, number, and currency formatting.

### **Default Language**

**Priority:** Medium

The system shall support default platform language configuration.

### **Future Language Support**

**Priority:** Medium

The system shall be designed to support future multi-language templates and interface content.

### **Phone Code Support**

**Priority:** High

The system shall support country phone codes for user contact numbers.

## **Regional Pricing & Availability**

### **Regional Subject Availability**

**Priority:** High

The system shall allow subjects, curricula, or offerings to be restricted or enabled by country.

### **Country-Specific Pricing Readiness**

**Priority:** Medium

The system shall support future country-specific student pricing configuration.

### **Country-Level Feature Flags**

**Priority:** High

The system shall support enabling or disabling features by country.

## **Regional Payment & Wallet**

### **Country Payment Availability**

**Priority:** High

The system shall control payment availability by country.

### **Razorpay India-First Configuration**

**Priority:** Critical

Version 1 shall support Razorpay as the primary payment gateway for INR-based operations.

### **Country Wallet Rules**

**Priority:** Medium

The system shall support future country-specific wallet limits and wallet behavior.

## **Regional Content**

### **Country Legal Content Readiness**

**Priority:** Medium

The system shall support future assignment of legal content by country.

### **Regional CMS Visibility**

**Priority:** Medium

The system shall support future country-based content visibility.

### **Regional SEO Readiness**

**Priority:** Medium

The system shall support future regional SEO pages and metadata.

## **Reports**

### **Country Filter in Reports**

**Priority:** High

Reports shall support country-based filtering where applicable.

### **Regional Analytics**

**Priority:** Medium

The system shall provide regional analytics for students, bookings, subjects, referrals, and marketplace behavior.

## **Governance**

### **Regional Settings Audit Log**

**Priority:** Critical

Changes to country, currency, timezone, regional features, and regional rules shall be audit logged.

### **Historical Regional Snapshot**

**Priority:** High

Transactions and bookings shall preserve relevant currency, country, and timezone context at the time of creation.

# **21.37 Business Rules**

### **BR-LOC-001**

- Version 1 shall operate India-first with INR as the primary financial currency.
- Razorpay shall be the primary payment gateway for Version 1.
- Confirmed lesson times shall be stored in UTC.
- Users shall view lesson times in their local timezone.
- Instructor availability shall be defined in instructor local timezone.
- Financial reports shall be INR-first in Version 1.
- Student-facing price and instructor compensation shall remain separate.
- Country-specific features shall not bypass core security, eligibility, payment, or audit rules.
- Inactive or restricted countries shall not be available for new operational workflows unless admin override is permitted.
- Historical records shall preserve the country, currency, and timezone context active at the time of transaction.
- Future exchange rate changes shall not modify historical financial records.
- Currency conversion, when introduced, must store conversion rate snapshots.
- Regional legal content must be displayed according to user country where enabled.
- Country-based report filters must respect admin permissions.

# **21.38 Validation Rules**

- Country code must be unique.
- Currency code must be valid and unique.
- Country default currency must reference an active currency.
- Country default timezone must be valid.
- User timezone must be valid.
- Country phone code must be valid.
- Active country must have required operational defaults.
- Payment-enabled country must have valid payment configuration.
- Wallet-enabled country must have valid wallet currency configuration.
- Archived currency cannot be used for new transactions.
- Country-level feature flag must reference a valid feature.
- Regional pricing rule must reference active country, subject, education level, and currency.

# **21.39 User Workflows**

## **Admin Creates Country**

- Admin opens Country Management.
- Admin creates new country record.
- Admin enters country code, name, currency, timezone, and phone code.
- System validates country data.
- Admin sets country status.
- System saves country.
- Change is audit logged.

## **Student Registers from Active Country**

- Student opens registration.
- Student selects country or system detects country.
- System verifies country availability.
- Student completes registration.
- System assigns country defaults.
- Student timezone and currency context are stored.

## **Student Views Lesson Time**

- Student opens instructor availability.
- Instructor availability exists in instructor timezone.
- System converts available slots to student timezone.
- Student selects slot.
- Booking stores confirmed time in UTC.
- Student and instructor see their respective local times.

## **Admin Enables Feature for Country**

- Admin opens country feature settings.
- Admin selects feature.
- Admin enables or disables feature for country.
- System validates dependencies.
- Admin confirms change.
- Setting is saved and audit logged.

## **Admin Reviews Country-Wise Report**

- Admin opens report dashboard.
- Admin selects country filter.
- System verifies report permission.
- System displays country-wise metrics.
- Admin exports report if permitted.
- Export is audit logged where required.

## **User Updates Timezone**

- User opens profile settings.
- User selects timezone.
- System validates timezone.
- System saves timezone.
- Future dashboards, notifications, and lesson times use updated timezone.

# **21.40 Exception Handling**

## **Unsupported Country**

If a user selects or is detected from an unsupported country, the system shall show an appropriate message and may allow waitlist or contact form where configured.

## **Invalid Timezone**

The system shall reject invalid timezone and prompt the user to choose a valid timezone.

## **Payment Disabled for Country**

The system shall prevent checkout and show payment unavailable message.

## **Currency Not Active**

The system shall prevent new transactions using inactive or archived currency.

## **Country Feature Dependency Missing**

The system shall prevent enabling feature and show missing dependency.

Example:

Wallet cannot be enabled for this country because no active currency is assigned.

## **Daylight Saving Conversion Issue**

The system shall use timezone database rules and display local times clearly to reduce confusion.

## **Historical Country Archived**

Reports shall continue to display historical transactions for archived countries.

# **21.41 Notifications**

Regional notifications may include:

## **Administrator**

- Country created
- Country status changed
- Currency status changed
- Payment disabled for country
- Feature enabled/disabled by country
- Regional configuration missing
- Timezone mismatch detected, future
- Country expansion checklist incomplete, future

## **User**

- Timezone updated
- Country not supported
- Payment unavailable in selected country
- Regional policy update, where required

# **21.42 Reports & Analytics**

Regional reports may include:

- Students by country
- Instructors by country
- Bookings by country
- Revenue by country
- Wallet activity by country
- Payment availability by country
- Referral activity by country
- Review trends by country
- Subject demand by country
- Timezone demand patterns
- Country-wise conversion
- Regional marketplace performance
- Regional feature usage

Version 1 reports remain INR-first for financial reporting.

# **21.43 Administrative Configuration**

Administrators shall be able to configure:

- Active countries
- Country status
- Default country
- Country currency
- Country timezone
- Country language
- Country phone code
- Country feature flags
- Regional support contacts
- Regional legal page mapping, future
- Regional CMS visibility, future
- Regional pricing, future
- Regional payment availability
- Regional report access

# **21.44 Acceptance Criteria**

- Administrators can manage countries, currencies, timezone defaults, and country statuses.
- Version 1 supports India-first INR operations with Razorpay as the primary payment gateway.
- Confirmed lesson times are stored in UTC and displayed in each user's local timezone.
- Country-level feature availability can be configured without bypassing core platform rules.
- Reports support country-based filters where applicable.
- Historical records preserve original country, currency, and timezone context.
- Currency and regional settings are validated before use in financial workflows.
- The system supports future readiness for country-specific pricing, content, legal pages, and payment gateways.
- Regional configuration changes are audit logged.
- Users receive clear local time, currency, and regional availability information.

# **21.45 Future Enhancements**

This module is designed to support future expansion, including:

- Multi-currency student wallets
- Stripe integration for US/UK
- Local payment gateways by country
- Country-specific tax rules
- GST/tax invoice rules
- Country-specific refund rules
- Country-specific instructor payouts
- Exchange rate management
- Regional pricing engine
- Multi-language interface
- Localized notification templates
- Country-specific legal pages
- Country-specific SEO landing pages
- Region-based instructor ranking
- Regional content campaigns
- Local support teams
- International compliance monitoring
- Country rollout checklist
- Regional business dashboards
- Multi-brand regional operations

# **21.46 Chapter Summary**

The Country, Currency, Localization & Regional Operations module prepares STEM Learning for global growth while preserving a practical India-first Version 1 operating model.

The chapter establishes INR as the primary financial currency, Razorpay as the primary payment gateway, and UTC as the standard storage format for confirmed lesson times.

It also defines the functional foundation for country management, currency readiness, timezone-safe scheduling, localized display, regional feature flags, country-wise reporting, and future international expansion.

By separating display currency, payment currency, wallet currency, settlement currency, and reporting currency conceptually, the platform can remain simple in Version 1 while being ready for future multi-country operations.

# **STEM Learning**

## **Enterprise Software Requirements Specification (SRS) v2.0**

# **BOOK 2 - FUNCTIONAL REQUIREMENTS**

# **PART F - PLATFORM ADMINISTRATION & OPERATIONS**

# **CHAPTER 22 - CMS, SEO, PUBLIC PAGES & CONTENT MANAGEMENT**

# **22.1 Introduction**

The CMS, SEO, Public Pages & Content Management module governs how STEM Learning manages public website content, landing pages, legal pages, FAQs, blog articles, SEO metadata, sitemap generation, robots configuration, redirects, public subject pages, instructor profile SEO, and content publishing workflows.

The CMS is the public communication layer of the platform. It supports marketing, trust-building, organic search growth, legal compliance, student education, instructor onboarding, and regional content expansion.

This module is especially important because STEM Learning is not only a logged-in application. It is also a public marketplace where students discover subjects, evaluate instructors, read help content, understand policies, and begin their learning journey.

# **22.2 Purpose**

The purpose of this module is to provide a flexible and administrator-managed content system for the public website.

The module must support:

- Public website pages
- Landing pages
- Legal pages
- FAQ content
- Blog articles
- SEO metadata
- Sitemap generation
- Robots configuration
- Redirect management
- Public subject pages
- Public instructor profile SEO
- Content publishing workflow
- Regional content readiness

The CMS should allow non-developer administrators to manage website content without code changes.

# **22.3 Business Objectives**

This module shall support the following business objectives:

- Enable public marketing pages.
- Improve organic SEO visibility.
- Support public instructor profile discovery.
- Support subject-based landing pages.
- Build student and parent trust.
- Publish legal and policy content.
- Reduce support workload through FAQs.
- Support blog and educational content marketing.
- Support future regional landing pages.
- Allow administrators to manage content without developer dependency.

# **22.4 Scope**

This chapter covers:

## **CMS**

- Pages
- Page blocks
- Landing pages
- Legal pages
- FAQs
- Blog posts
- Categories
- Tags
- Media
- Publishing workflow

## **SEO**

- Meta title
- Meta description
- Canonical URL
- Open Graph metadata
- Robots metadata
- Sitemap
- Robots.txt
- Redirects
- Structured data readiness

## **Public Pages**

- Homepage
- About page
- Contact page
- Become Instructor page
- Subject landing pages
- Instructor profile pages
- FAQ pages or FAQ sections
- Legal policy pages
- Blog/articles

## **Administration**

- Content management
- Content scheduling
- Content visibility
- Content preview
- Content revisions, future
- Content audit logs

# **22.5 CMS Philosophy**

The CMS should be flexible but controlled.

The platform should avoid hard-coding content that business teams need to update frequently.

The recommended principle is:

Public content should be editable by authorized administrators, while platform workflows remain controlled by application logic.

The CMS should manage content, not core business transactions.

# **22.6 Public Website Role**

The public website serves multiple purposes:

- Explain the platform.
- Build trust.
- Attract students.
- Attract instructors.
- Support SEO.
- Convert visitors into registrations.
- Convert visitors into demo bookings.
- Provide legal transparency.
- Reduce repetitive support queries.

The public website is part of the marketplace funnel.

# **22.7 Page Management**

Administrators shall be able to create and manage public pages.

Page examples:

- Home
- About Us
- Contact Us
- How It Works
- Become an Instructor
- For Students
- Pricing, if needed
- Demo Lesson
- Online Maths Tutors
- Online Coding Tutors
- Online English Tutors
- Privacy Policy
- Terms & Conditions
- Refund Policy

Pages should support status, visibility, content blocks, SEO metadata, and publishing controls.

# **22.8 Page Status**

Pages may have the following statuses:

## **Draft**

Page is being prepared and is not publicly visible.

## **Published**

Page is live and publicly visible.

## **Scheduled**

Page will be published at a future date and time.

## **Unpublished**

Page is not public but remains available in admin.

## **Archived**

Page is retired and preserved for history.

Only published pages should be publicly accessible.

# **22.9 Page Visibility**

Pages may support visibility rules.

Examples:

- Public
- Guest only
- Authenticated users
- Students
- Instructors
- Administrators
- Country-specific, future

Most CMS marketing pages are public.

Legal pages should generally be publicly accessible.

# **22.10 Content Blocks**

Pages should support structured content blocks.

Examples:

- Hero section
- Text block
- Image block
- Video block
- CTA block
- FAQ block
- Feature grid
- Testimonial block
- Instructor highlight block
- Subject list block
- Pricing explanation block
- Contact form block
- Rich text block
- HTML/embed block, restricted

Content blocks allow flexible page design without custom development for every landing page.

# **22.11 Landing Pages**

Landing pages are focused pages designed for conversion.

Examples:

- Online Maths Tutors
- Learn Python Online
- English Speaking Classes
- One-to-One Coding Classes
- Become an Instructor
- Free Demo Class
- Online STEM Learning for Kids

Landing pages should support:

- SEO metadata
- Clear call-to-action
- Subject association
- Country targeting, future
- Featured instructors, where relevant
- FAQs
- Testimonials, future
- Conversion tracking

# **22.12 Legal Pages**

Legal pages are required for trust and compliance.

Required legal pages may include:

- Terms & Conditions
- Privacy Policy
- Refund Policy
- Cookie Policy
- Disclaimer
- Instructor Agreement
- Student Agreement
- Recording Consent Policy
- Data Retention Policy, future

Legal pages should be versioned or at least preserve update history.

Legal page updates may require user notification where policy requires.

# **22.13 FAQ Management**

The platform shall support FAQ content.

FAQ categories may include:

- Public User FAQs
- Student FAQs
- Instructor FAQs
- Payment FAQs
- Booking FAQs
- Wallet FAQs
- Refund FAQs
- Demo Lesson FAQs
- Technical FAQs

Based on current business preference, FAQs do not require separate SEO slug pages in Version 1.

Recommended Version 1 approach:

FAQs are managed as categorized accordion items and embedded into relevant pages or dashboards.

This supports:

- Public FAQ accordion
- Student dashboard FAQ accordion
- Instructor dashboard FAQ accordion
- Contextual FAQ blocks

# **22.14 FAQ Structure**

Each FAQ item may include:

- Question
- Answer
- Category
- Audience
- Sort order
- Status
- Related module
- Featured flag
- Country visibility, future

FAQ audiences may include:

- Public
- Student
- Instructor
- Admin/support, future

FAQ answers may support rich text but should avoid unsafe HTML.

# **22.15 Blog & Articles**

The platform may support blog or article content for SEO and education.

Blog examples:

- How to choose an online maths instructor
- Benefits of one-to-one coding classes
- How free demo lessons work
- Tips for improving English speaking
- Learning Python for beginners
- Online learning safety tips

Blog content supports:

- SEO
- Marketing
- Student education
- Instructor thought leadership, future
- Organic traffic growth

# **22.16 Blog Categories and Tags**

Blog posts may be organized by categories and tags.

Examples:

Categories:

- Learning Tips
- STEM Education
- Coding
- Maths
- English
- Platform Updates
- Instructor Guides

Tags:

- Python
- Algebra
- Online Learning
- Demo Class
- Study Tips
- One-to-One Learning

Categories and tags improve content organization and SEO.

# **22.17 Author Strategy**

Author information should use platform users rather than a separate author identity where possible.

Possible author types:

- Admin author
- Instructor author, future
- Platform editorial team

For Version 1, blog authors may be selected from approved internal users.

Future instructor-authored articles may require review workflow.

# **22.18 Media Management**

The CMS should support media assets.

Examples:

- Images
- Illustrations
- Blog images
- Instructor profile media
- Page banners
- PDFs
- Legal files
- Downloadable resources, where applicable

Media should support:

- File validation
- Alt text
- Caption
- Collection/grouping
- Responsive display
- Replacement
- Deletion/archive policy

Media must follow platform file security rules.

# **22.19 SEO Metadata**

Pages, blog posts, subject landing pages, and instructor profiles should support SEO metadata.

SEO fields may include:

- SEO title
- Meta description
- Canonical URL
- Meta robots
- Open Graph title
- Open Graph description
- Open Graph image
- Twitter/social metadata, future
- Structured data, future

SEO metadata should have safe fallbacks.

# **22.20 SEO Fallback Strategy**

If custom SEO metadata is missing, the system should use fallback values.

Example fallback chain:

Page SEO

│

▼

Entity SEO

│

▼

Global SEO Settings

│

▼

Platform Default SEO

This prevents empty or poor metadata on public pages.

# **22.21 Public Instructor Profile SEO**

Instructor profiles are important SEO assets.

Instructor profile SEO may include:

- Instructor name
- Subject expertise
- Teaching languages
- Country
- Education levels
- Rating
- Reviews
- Availability summary
- Meta title
- Meta description
- Open Graph image
- Structured data readiness

Example SEO theme:

Online Python Instructor for One-to-One Lessons

Public instructor profile SEO must never expose private contact details.

# **22.22 Public Subject Page SEO**

Subject pages are important for organic growth.

Examples:

- Online Maths Tutors
- Online Physics Classes
- Learn Python Online
- English Speaking Instructor
- One-to-One Chemistry Lessons

Subject pages may include:

- Subject overview
- Education levels
- Learning outcomes
- Featured instructors
- FAQs
- Related subjects
- CTA to search instructors
- SEO metadata

# **22.23 Sitemap Management**

The platform shall support sitemap generation.

Sitemap may include:

- Published pages
- Blog posts
- Subject pages
- Instructor profiles
- Country landing pages, future

The sitemap should exclude:

- Draft pages
- Archived pages
- Private pages
- Suspended instructor profiles
- Noindex pages
- Admin pages
- Authenticated dashboards

# **22.24 Robots.txt Management**

Administrators may configure robots.txt content.

Robots configuration should protect:

- Admin routes
- Login routes
- Dashboard routes
- Search result pages, if needed
- Private resources
- Internal files

Robots configuration must not expose private paths or sensitive internal details unnecessarily.

# **22.25 Redirect Management**

The platform shall support redirects for SEO and content changes.

Redirect examples:

- Old page URL to new page URL
- Changed subject page slug
- Archived landing page to relevant category
- Blog URL correction
- Legal page URL update

Redirect types:

- 301 permanent
- 302 temporary

Redirects help preserve SEO value and prevent broken links.

# **22.26 Slug Management**

Public content should use clean URLs.

Examples:

/about

/become-instructor

/online-maths-tutors

/instructors/john-smith

/blog/how-to-learn-python-online

Slug rules:

- Unique within content type or route scope
- Lowercase
- SEO-friendly
- Avoid special characters
- Support redirect on slug change
- Preserve historical links where possible

# **22.27 Content Scheduling**

Administrators may schedule content publishing.

Scheduling supports:

- Campaign launches
- Blog publishing
- Regional content
- Legal page update timing
- Promotional pages

Scheduled content should become visible automatically at configured date/time.

# **22.28 Content Preview**

Administrators should be able to preview content before publishing.

Preview should allow:

- Draft page preview
- Desktop view
- Mobile view, future
- SEO preview, future
- Block layout check

Preview URLs must not be publicly indexable.

# **22.29 Content Versioning**

Versioning is recommended for future.

Content versioning may track:

- Previous content
- Current content
- Changed by
- Changed at
- Change reason
- Publish history

Versioning is especially important for legal pages.

Version 1 may start with update history and activity logs.

# **22.30 Content Approval Workflow**

For Version 1, content may be published directly by authorized administrators.

Future approval workflow may include:

Draft

│

▼

Submitted for Review

│

▼

Approved

│

▼

Published

Approval workflow may be useful when editorial, legal, or marketing teams expand.

# **22.31 Regional CMS Readiness**

CMS content should be future-ready for country-specific visibility.

Examples:

- India homepage banner
- UK subject landing page
- US pricing explanation
- Country-specific legal notice
- Region-specific FAQs
- Country-specific instructor marketplace pages

Version 1 may not require full regional CMS but should avoid design choices that block it later.

# **22.32 CMS Search**

Administrators should be able to search content.

Searchable content:

- Pages
- Blog posts
- FAQs
- Legal pages
- Redirects
- Media
- SEO titles
- Slugs

Search helps manage growing content.

# **22.33 Public Search Engine Indexing**

Only approved public content should be indexable.

Indexable content may include:

- Published public pages
- Published blog posts
- Published subject pages
- Approved active instructor profiles

Non-indexable content includes:

- Draft content
- Auth dashboards
- Admin pages
- Checkout pages
- Wallet pages
- Private learning pages
- Suspended instructor profiles

# **22.34 Structured Data Readiness**

Future SEO may support structured data.

Possible structured data types:

- Organization
- WebSite
- BreadcrumbList
- FAQPage
- Article
- Person, for instructor profile where appropriate
- Course, where appropriate
- Review, where appropriate and policy-compliant

Structured data should be accurate and not misleading.

# **22.35 Content Analytics**

CMS analytics may include:

- Page views
- Landing page conversions
- Blog views
- CTA clicks
- Instructor profile visits
- Subject page visits
- FAQ engagement
- Search engine traffic, future
- Conversion to registration
- Conversion to demo booking

CMS analytics connect marketing content with business outcomes.

# **22.36 Admin CMS Interface**

The admin CMS interface should include:

- Pages
- Blog posts
- Categories
- Tags
- FAQs
- Media
- Redirects
- SEO settings
- Sitemap/robots settings
- Legal pages
- Content blocks
- Publishing status
- Preview action

Interface access should be permission-controlled.

# **22.37 Functional Requirements**

## **Page Management**

### **Page Creation**

**Priority:** Critical

The system shall allow authorized administrators to create public CMS pages.

### **Page Editing**

**Priority:** Critical

The system shall allow authorized administrators to edit CMS pages.

### **Page Status**

**Priority:** Critical

The system shall support draft, published, scheduled, unpublished, archived page statuses.

### **Page Visibility**

**Priority:** High

The system shall support visibility rules for CMS pages.

### **Page Slug**

**Priority:** Critical

The system shall generate and manage SEO-friendly page slugs.

### **Page Preview**

**Priority:** High

The system shall allow authorized administrators to preview draft content.

## **Content Blocks**

### **Block-Based Content**

**Priority:** High

The system shall support structured content blocks for CMS pages.

### **Block Ordering**

**Priority:** High

Administrators shall be able to reorder content blocks.

### **Block Visibility**

**Priority:** Medium

Content blocks may support visibility and status rules.

## **Legal Pages**

### **Legal Page Management**

**Priority:** Critical

The system shall allow administrators to manage legal pages such as Terms, Privacy Policy, Refund Policy, Cookie Policy, and Disclaimer.

### **Legal Page Update History**

**Priority:** High

The system shall preserve update history or activity logs for legal pages.

## **FAQ**

### **FAQ Management**

**Priority:** High

The system shall allow administrators to manage FAQ items.

### **FAQ Categories**

**Priority:** High

The system shall support FAQ categories and audience targeting.

### **FAQ Accordion Display**

**Priority:** High

The system shall support FAQ display in accordion format.

### **Contextual FAQ Blocks**

**Priority:** Medium

The system shall allow FAQ blocks to be embedded into relevant pages or dashboards.

## **Blog**

### **Blog Post Management**

**Priority:** High

The system shall allow administrators to create, edit, publish, and archive blog posts.

### **Blog Categories**

**Priority:** Medium

The system shall support blog categories.

### **Blog Tags**

**Priority:** Medium

The system shall support blog tags.

### **Blog Author Assignment**

**Priority:** Medium

The system shall allow blog posts to be associated with an approved author.

## **Media**

### **Media Upload**

**Priority:** High

The system shall allow authorized administrators to upload media assets.

### **Media Metadata**

**Priority:** Medium

The system shall support media metadata such as alt text and caption.

**Media Validation**

**Priority:** Critical

Uploaded media shall comply with configured file type and size restrictions.

## **SEO**

### **SEO Metadata**

**Priority:** Critical

The system shall allow SEO metadata management for public content.

### **SEO Fallback**

**Priority:** High

The system shall provide fallback SEO metadata when custom metadata is missing.

### **Open Graph Metadata**

**Priority:** Medium

The system shall support Open Graph metadata for public content.

### **Canonical URL**

**Priority:** High

The system shall support canonical URL configuration where applicable.

### **Meta Robots**

**Priority:** High

The system shall support meta robots settings such as index/noindex.

## **Sitemap & Robots**

### **Sitemap Generation**

**Priority:** Critical

The system shall generate sitemap entries for eligible public content.

### **Sitemap Exclusion**

**Priority:** Critical

The system shall exclude draft, private, archived, suspended, and noindex content from sitemap.

### **Robots.txt Management**

**Priority:** High

The system shall support robots.txt configuration.

## **Redirects**

### **Redirect Management**

**Priority:** High

The system shall allow administrators to create and manage redirects.

### **Slug Change Redirect**

**Priority:** Medium

The system may automatically create redirects when public slugs change.

### **Redirect Validation**

**Priority:** High

The system shall validate redirect source and destination to avoid loops and conflicts.

## **Public Subject Pages**

### **Subject Landing Page**

**Priority:** High

The system shall support public subject landing pages.

### **Subject SEO Metadata**

**Priority:** High

Subject landing pages shall support SEO metadata.

### **Subject CTA**

**Priority:** High

Subject landing pages shall include calls-to-action to browse instructors or book demo lessons where applicable.

## **Instructor Profile SEO**

### **Instructor Profile SEO**

**Priority:** High

Public instructor profiles shall support SEO metadata and safe fallback content.

### **Instructor Profile Sitemap**

**Priority:** Medium

Approved active instructor profiles may be included in sitemap where indexing is enabled.

### **Suspended Instructor Noindex**

**Priority:** Critical

Suspended or inactive instructor profiles shall not be publicly indexed.

## **Publishing & Governance**

### **Scheduled Publishing**

**Priority:** Medium

The system shall support scheduled publishing of CMS content.

### **Content Audit Log**

**Priority:** Critical

The system shall audit log important content creation, updates, publication, and deletion/archive actions.

### **CMS Permission Control**

**Priority:** Critical

CMS access shall be controlled by role and permission.

### **Content Search**

**Priority:** Medium

Administrators shall be able to search CMS content.

## **Analytics**

### **Content Analytics**

**Priority:** Medium

The system shall support content analytics such as page views, CTA clicks, and conversions in future-ready design.

# **22.38 Business Rules**

Only published public content shall be accessible publicly.

Draft, archived, private, and noindex content shall not be included in sitemap.

Legal pages shall be publicly accessible unless explicitly restricted by policy.

FAQ content shall be manageable by category and audience.

Version 1 FAQs may be displayed as accordions rather than standalone SEO pages.

CMS content shall not control financial, booking, wallet, payment, or security workflows.

Public instructor profiles shall never expose private instructor contact information.

Suspended, inactive, or archived instructor profiles shall not be publicly indexed.

Slug changes should preserve SEO value through redirects where applicable.

Robots and sitemap configuration must not expose private application routes.

SEO metadata should have fallback values.

CMS publishing actions must be permission-controlled.

Content updates to legal pages should preserve update history.

Media uploads must comply with platform file security rules.

# **22.39 Validation Rules**

- Page title is required.
- Published page must have a valid slug.
- Slug must be unique within its route scope.
- Scheduled publish date must be valid.
- SEO title length should follow configured limits.
- Meta description length should follow configured limits.
- FAQ question and answer are required.
- Redirect source and destination must not be identical.
- Redirect loops are not permitted.
- Uploaded media type must be allowed.
- Uploaded media size must not exceed configured limit.
- Noindex content must not be included in sitemap.

# **22.40 User Workflows**

## **Admin Creates Public Page**

- Admin opens CMS Pages.
- Admin creates a new page.
- Admin enters title and slug.
- Admin adds content blocks.
- Admin configures SEO metadata.
- Admin previews page.
- Admin publishes page.
- System makes page public.
- Sitemap updates where applicable.

## **Admin Updates Legal Page**

- Admin opens Legal Pages.
- Admin selects legal page.
- Admin updates content.
- System records change history.
- Admin publishes update.
- Public legal page reflects new content.
- Change is audit logged.

## **Admin Creates FAQ Item**

- Admin opens FAQ management.
- Admin creates FAQ item.
- Admin selects audience.
- Admin selects category.
- Admin enters question and answer.
- Admin sets sort order.
- Admin publishes FAQ.
- FAQ appears in relevant accordion.

## **Admin Creates Blog Post**

- Admin opens Blog Posts.
- Admin creates new blog post.
- Admin enters title, slug, content, category, tags, and author.
- Admin uploads featured image.
- Admin configures SEO metadata.
- Admin previews post.
- Admin publishes or schedules post.
- Blog post becomes publicly accessible when published.

## **Admin Creates Redirect**

- Admin opens Redirects.
- Admin enters source path.
- Admin enters destination path or URL.
- Admin selects redirect type.
- System validates redirect.
- Admin saves redirect.
- Redirect becomes active.

## **Public User Views Subject Landing Page**

- Public user visits subject page.
- System checks page status and visibility.
- System loads subject content.
- System displays SEO-friendly page.
- User clicks browse instructors or book demo.
- Marketplace journey begins.

# **22.41 Exception Handling**

## **Page Not Published**

The system shall return not found or restricted response for unpublished public pages.

## **Slug Conflict**

The system shall prevent saving duplicate slug and request another slug.

## **Scheduled Publish Failure**

The system shall log failure and notify administrators.

## **Redirect Loop Detected**

The system shall reject redirect configuration.

## **Media Upload Failed**

The system shall show a safe error message and preserve page draft content.

## **SEO Metadata Missing**

The system shall apply fallback SEO metadata.

## **Instructor Profile Inactive**

The system shall hide or noindex instructor profile according to marketplace policy.

# **22.42 Notifications**

CMS-related notifications may include:

## **Administrator**

- Scheduled content published
- Scheduled publish failed
- Legal page updated
- High-impact public page changed
- Redirect created
- Sitemap generation failed
- Media upload failed
- Content awaiting approval, future

# **22.43 Reports & Analytics**

CMS reports may include:

- Published pages count
- Draft pages count
- Blog posts published
- FAQ usage
- Top viewed pages
- Landing page conversion
- CTA click rate
- Subject page traffic
- Instructor profile organic traffic
- Broken link reports
- Redirect usage
- Sitemap status
- SEO metadata completeness

# **22.44 Administrative Configuration**

Administrators shall be able to configure:

- CMS enabled/disabled
- Page publishing permissions
- Blog enabled/disabled
- FAQ enabled/disabled
- Sitemap enabled/disabled
- Robots.txt content
- Redirect behavior
- Allowed media types
- Maximum media size
- SEO fallback settings
- Open Graph defaults
- Legal page mappings
- Content approval workflow, future
- Regional content visibility, future

# **22.45 Acceptance Criteria**

Authorized administrators can create, edit, preview, publish, schedule, unpublish, and archive CMS pages.

Pages support structured content blocks and SEO metadata.

Legal pages can be managed and update history is preserved.

FAQs can be managed by category and audience and displayed as accordions.

Blog posts can be created, categorized, tagged, authored, and published.

Published public content can be included in sitemap while private, draft, archived, and noindex content is excluded.

Robots.txt and redirects can be managed by authorized administrators.

Public instructor profiles and subject pages support SEO-safe metadata.

Suspended or inactive instructor profiles are not publicly indexed.

CMS actions are permission-controlled and audit logged.

# **22.46 Future Enhancements**

This module is designed to support future expansion, including:

- Full content versioning
- Editorial approval workflow
- Multi-language content
- Country-specific CMS content
- Regional landing pages
- A/B testing
- Landing page builder
- Visual page builder
- SEO score assistant
- AI content suggestions
- AI FAQ generation
- Structured data automation
- Broken link scanner
- Content performance dashboard
- Content personalization
- Instructor-authored articles
- Student success stories
- Testimonials module
- Newsletter content
- Resource downloads
- Lead capture forms
- Marketing campaign pages
- Integration with analytics tools
- Programmatic SEO pages

# **22.47 Chapter Summary**

The CMS, SEO, Public Pages & Content Management module defines the public content foundation of STEM Learning.

It enables administrators to manage public pages, legal pages, FAQs, blogs, landing pages, media, SEO metadata, sitemap, robots configuration, redirects, subject pages, and instructor profile SEO without developer dependency.

This module supports organic growth, student trust, instructor discovery, policy transparency, marketing campaigns, and future regional expansion.

By combining flexible content blocks, controlled publishing, SEO metadata, legal content management, FAQ accordions, public subject pages, instructor profile SEO, and audit logging, STEM Learning can operate as both a learning platform and a scalable public marketplace.

# **CHAPTER 23 - ADMIN USER MANAGEMENT, ROLES, PERMISSIONS & ACCESS CONTROL**

# **23.1 Introduction**

The Admin User Management, Roles, Permissions & Access Control module defines how internal platform users are created, assigned roles, granted permissions, restricted from sensitive areas, and monitored for accountability.

STEM Learning includes many sensitive administrative areas such as payments, wallet, instructor earnings, withdrawals, instructor verification, booking overrides, meeting monitoring, CMS publishing, reports, support disputes, and configuration settings.

Because these actions affect money, trust, privacy, student experience, instructor reputation, and platform operations, admin access must be role-based, permission-controlled, auditable, and aligned with the principle of least privilege.

This module focuses on internal platform administration. Student and instructor authentication are covered in earlier identity chapters.

# **23.2 Purpose**

The purpose of this module is to provide a secure internal access control system for platform administrators and operations staff.

The module must ensure:

- Admin users can be created and managed securely.
- Admin roles define access boundaries.
- Permissions are grouped by platform domain.
- Sensitive actions require specific permissions.
- Financial permissions are restricted.
- Audit-sensitive actions are logged.
- Admin access follows least privilege.
- Suspended or inactive admins cannot access the admin panel.
- Future support-agent workflows can be safely introduced.

# **23.3 Business Objectives**

This module shall support the following business objectives:

- Protect sensitive business operations.
- Prevent unauthorized financial actions.
- Maintain admin accountability.
- Support role-based operational teams.
- Allow safe delegation of work.
- Reduce risk of accidental or malicious changes.
- Support enterprise audit requirements.
- Support scalable admin operations.
- Enable future support and compliance workflows.
- Maintain separation between business domains.

# **23.4 Scope**

This chapter covers:

## **Admin Users**

- Admin account creation
- Admin profile
- Admin status
- Admin access lifecycle
- Admin suspension
- Admin deactivation

## **Roles**

- Super Admin
- Operations Admin
- Finance Admin
- Academic Admin
- Marketplace Admin
- CMS Manager
- Support Agent
- Compliance/Audit Viewer
- Custom roles

## **Permissions**

- Domain permissions
- Action permissions
- Sensitive permissions
- Financial permissions
- Report permissions
- Configuration permissions

## **Access Control**

- Admin panel access
- Feature access
- Module access
- Record-level access, future
- Sensitive action confirmation
- Audit logging

# **23.5 Access Control Philosophy**

The platform shall follow the principle of least privilege.

Admin users should only access the areas and actions required for their role.

No administrator should receive full access unless required.

The system should avoid one shared admin account or informal access sharing.

Every admin action must be attributable to a specific internal user.

# **23.6 Admin User Types**

The platform may support different internal user types.

## **Super Admin**

Has highest-level access across the platform.

Used for ownership, final control, and emergency management.

## **Operations Admin**

Manages bookings, availability, meetings, no-shows, cancellations, support workflows, and day-to-day operations.

## **Finance Admin**

Manages payments, wallet reports, refunds, instructor earnings, settlements, and withdrawals.

## **Academic Admin**

Manages academic framework, subjects, curricula, learning plans, homework oversight, and academic quality.

## **Marketplace Admin**

Manages instructor marketplace, public profiles, discovery, featured instructors, reviews, and trust signals.

## **CMS Manager**

Manages pages, blogs, FAQs, legal pages, SEO, redirects, sitemap, and public content.

## **Support Agent**

Handles student and instructor support cases, booking questions, technical issues, and dispute triage.

This may be future or limited in Version 1.

## **Compliance / Audit Viewer**

Can view audit logs, reports, and sensitive history without modifying operational data.

# **23.7 Admin Account Lifecycle**

Admin accounts follow a lifecycle.

Invited

│

▼

Active

│

▼

Role Assigned

│

▼

Operational Access

│

▼

Suspended / Deactivated

│

▼

Archived

Each lifecycle transition should be permission-controlled and audit logged.

# **23.8 Admin Status Definitions**

## **Invited**

Admin has been invited but has not completed setup.

## **Active**

Admin can access permitted areas.

## **Suspended**

Admin access is temporarily blocked.

## **Deactivated**

Admin no longer has access.

## **Archived**

Admin account is retained for history and audit records.

Archived admins cannot log in.

# **23.9 Admin Profile**

Admin profile may include:

- Name
- Email
- Phone, optional
- Role
- Department/team, optional
- Status
- Last login
- Two-factor status, future
- Created by
- Created at
- Updated by
- Updated at

Admin personal information should be limited to operational needs.

# **23.10 Role-Based Access Control**

Role-Based Access Control defines what an admin can access based on assigned role.

Examples:

- Finance Admin can access wallet, payments, earnings, withdrawals, and financial reports.
- CMS Manager can access pages, blogs, FAQs, SEO, redirects, and media.
- Academic Admin can access subjects, curricula, learning plans, homework, and educational analytics.
- Operations Admin can access bookings, availability, meetings, cancellations, and no-show reports.
- Support Agent can access support-related user and booking information but not financial adjustment actions.

Roles simplify permission management.

# **23.11 Permission-Based Access Control**

Permissions define granular actions.

Examples:

- View bookings
- Cancel booking
- Override booking
- View wallet
- Manual wallet credit
- Approve withdrawal
- Publish CMS page
- Update payment settings
- View audit logs
- Export financial reports

Roles may contain multiple permissions.

Sensitive permissions should not be granted automatically.

# **23.12 Permission Groups**

Permissions should be organized by domain.

Recommended permission groups:

- Dashboard
- Students
- Instructors
- Instructor Verification
- Academic Framework
- Learning Plans
- Homework & Resources
- Marketplace
- Availability
- Booking
- Meeting
- Wallet
- Payment
- Instructor Earnings
- Withdrawals
- Referrals & Promotions
- Reviews & Quality
- Notifications & Messaging
- CMS & SEO
- Reports & Analytics
- Global Settings
- Countries & Localization
- Support & Disputes
- Activity Logs & Audit
- System Health

# **23.13 Standard Permission Actions**

Each domain may support actions such as:

- View
- Create
- Edit
- Delete
- Archive
- Restore
- Approve
- Reject
- Publish
- Export
- Override
- Assign
- Review
- Adjust
- Configure

Not all actions apply to every domain.

# **23.14 Sensitive Permissions**

Sensitive permissions require stricter control.

Examples:

- Manual wallet credit
- Manual wallet debit
- Payment verification retry
- Refund approval
- Instructor earning adjustment
- Withdrawal approval
- Withdrawal paid marking
- Booking override
- Cancellation override
- Meeting observer access
- View private messages
- View financial reports
- Export personal data
- Update payment settings
- Update security settings
- Update admin roles
- View audit logs

Sensitive permissions should be limited and audit logged.

# **23.15 Financial Access Restrictions**

Financial access must be tightly controlled.

Financial domains include:

- Wallet
- Payments
- Refunds
- Instructor earnings
- Instructor withdrawals
- Financial reports
- Razorpay reconciliation
- Manual financial adjustments

Rules:

- Only finance-authorized roles may access financial modules.
- Manual financial actions require reason.
- Financial exports require export permission.
- High-value financial actions may require approval in future.
- Financial actions must be audit logged.

# **23.16 Admin Role Examples**

Recommended Version 1 roles:

## **Super Admin**

Full access.

Use only for owner-level users.

## **Operations Manager**

Can manage:

- Bookings
- Availability
- Meetings
- No-shows
- Cancellations
- Reschedules
- Technical issues
- Operational reports

Cannot manage:

- Wallet adjustments
- Payment settings
- Instructor pay rules
- Admin roles

## **Finance Manager**

Can manage:

- Payments
- Wallet reports
- Refund review
- Instructor earnings
- Withdrawals
- Financial reports
- Reconciliation

Cannot manage:

- CMS publishing
- Academic framework
- Instructor profile approval unless needed

## **Academic Manager**

Can manage:

- Subjects
- Education levels
- Curriculum
- Learning roadmaps
- Learning plans
- Homework oversight
- Academic reports

Cannot manage:

- Payment and wallet settings
- Instructor withdrawals

## **Marketplace Manager**

Can manage:

- Instructor public profiles
- Featured instructors
- Discovery settings
- Reviews and ratings
- Quality alerts
- Marketplace reports

Cannot manage:

- Wallet credits
- Payment reconciliation
- Security settings

## **CMS Editor**

Can manage:

- Pages
- Blog posts
- FAQs
- Media
- SEO metadata
- Redirects

Cannot manage:

- Payments
- Wallet
- Bookings
- Instructor earnings

## **Support Agent**

Can view limited:

- Student profile
- Instructor profile
- Booking history
- Support cases
- Technical issue reports
- Message reports, if assigned

Can perform limited actions:

- Add support notes
- Escalate case
- Request admin review

Cannot perform:

- Financial adjustments
- Withdrawal approval
- Admin role changes
- Security settings changes

# **23.17 Super Admin Controls**

Super Admin has broad access but must still be auditable.

Super Admin actions should be logged, including:

- Role changes
- Permission changes
- Financial setting changes
- Security setting changes
- Admin account creation
- Payment configuration changes
- High-impact platform settings
- Sensitive exports

Super Admin should not be used for daily routine operations where lower roles are sufficient.

# **23.18 Admin Panel Access**

Admin panel access shall be restricted to authorized internal users.

Rules:

- Students shall not access admin panel.
- Instructors shall not access admin panel unless explicitly assigned internal admin role, which is not recommended.
- Admin access requires active admin status.
- Suspended/deactivated admins cannot log in.
- Admin routes must be protected by authentication and permission checks.
- Admin access attempts may be logged.

# **23.19 Record-Level Access**

Version 1 may use role-based permissions.

Future record-level access may restrict:

- Support agents to assigned cases.
- Finance users to assigned country.
- Academic admins to specific subject areas.
- Marketplace managers to instructor groups.
- Operations users to specific regions.

Record-level access is future-ready and may become important as the team grows.

# **23.20 Department or Team Assignment**

Admin users may optionally belong to departments or teams.

Examples:

- Finance
- Operations
- Academic
- Marketing
- Support
- Compliance
- Technology

Department assignment may support filtering, reporting, and future approval workflows.

# **23.21 Admin Invitation**

Admin accounts may be created through invitation.

Invitation flow:

Super Admin creates admin invitation

│

▼

Assigns role

│

▼

Admin receives invitation email

│

▼

Admin sets password

│

▼

Admin account becomes active

Invitation links should expire.

# **23.22 Admin Password and Security**

Admin accounts should follow strong security rules.

Recommended controls:

- Strong password policy
- Email verification
- Session timeout
- Login attempt limits
- Password reset flow
- Two-factor authentication, future
- Login alerts, future
- Sensitive action confirmation, future

Admin security should be stronger than ordinary student access.

# **23.23 Two-Factor Authentication**

Two-factor authentication is recommended for future and may be required for:

- Super Admin
- Finance Admin
- Security Admin
- Admins with withdrawal approval
- Admins with wallet adjustment permission
- Admins with settings permission

Version 1 may include future readiness even if not enforced initially.

# **23.24 Sensitive Action Confirmation**

Sensitive actions may require additional confirmation.

Examples:

- Approve withdrawal
- Manual wallet debit
- Manual wallet credit
- Change payment settings
- Change security settings
- Suspend instructor
- Override booking refund
- Export sensitive report
- Assign Super Admin role

Future versions may require password re-entry or 2FA confirmation.

# **23.25 Admin Activity Logging**

Admin actions must be logged.

Examples:

- Login
- Logout
- User creation
- Role assignment
- Permission update
- Booking override
- Wallet adjustment
- Payment retry
- Withdrawal approval
- CMS publication
- Settings update
- Report export
- Audit log view

Activity logs support accountability and investigation.

# **23.26 Admin Impersonation**

Admin impersonation is high-risk.

If supported in future, it must be heavily controlled.

Rules:

- Only Super Admin or authorized support role may impersonate.
- Impersonation requires reason.
- User must be notified where policy requires.
- All actions during impersonation must be logged.
- Financial actions during impersonation should be blocked.

Recommended Version 1:

Do not support user impersonation initially.

Use support views instead.

# **23.27 Admin Notes**

Admin users may add internal notes to records.

Examples:

- Student support note
- Instructor verification note
- Booking dispute note
- Payment reconciliation note
- Withdrawal review note
- Quality review note

Admin notes should be permission-controlled and audit logged.

Internal notes must never appear publicly.

# **23.28 Admin Access Review**

The platform should support periodic access review.

Access review may include:

- Active admins
- Last login
- Assigned roles
- Sensitive permissions
- Financial permissions
- Export permissions
- Inactive admin accounts
- Suspended accounts

Future versions may require scheduled access certification.

# **23.29 Separation of Duties**

Certain actions should not be controlled by one person in future.

Examples:

- One admin creates withdrawal, another approves.
- One admin creates pay rule, another approves.
- One admin updates payment configuration, another verifies.
- One admin adjusts wallet, another reviews high-value adjustment.

Version 1 may use role control; future versions may use approval workflows.

# **23.30 Permission Change Governance**

Role and permission changes are sensitive.

Rules:

- Only authorized admins may change roles.
- Permission changes must be audit logged.
- Super Admin assignment should require confirmation.
- Admin cannot remove their own only Super Admin access without safeguard.
- Permission change history must be preserved.

# **23.31 Admin User Deactivation**

When an admin leaves or no longer needs access:

- Admin account should be deactivated.
- Sessions should be revoked.
- API access should be disabled, if any.
- Pending assignments should be reassigned.
- Historical activity logs remain preserved.

Deleted admin accounts should be avoided if audit records reference them.

# **23.32 Functional Requirements**

## **Admin User Management**

### **Admin User Creation**

**Priority:** Critical

The system shall allow authorized administrators to create internal admin users.

### **Admin Invitation**

**Priority:** High

The system shall support inviting admin users through secure invitation flow.

### **Admin Profile Management**

**Priority:** High

The system shall allow authorized administrators to manage admin user profile details.

### **Admin Status Management**

**Priority:** Critical

The system shall support admin statuses such as invited, active, suspended, deactivated, and archived.

### **Admin Deactivation**

**Priority:** Critical

The system shall allow authorized administrators to deactivate admin accounts without deleting audit history.

## **Roles**

### **Role Management**

**Priority:** Critical

The system shall allow authorized administrators to create and manage admin roles.

### **Role Assignment**

**Priority:** Critical

The system shall allow authorized administrators to assign roles to admin users.

### **Standard Roles**

**Priority:** High

The system shall support standard roles such as Super Admin, Operations Manager, Finance Manager, Academic Manager, Marketplace Manager, CMS Editor, and Support Agent.

### **Custom Roles**

**Priority:** Medium

The system shall support custom roles where business operations require additional access patterns.

## **Permissions**

### **Permission Management**

**Priority:** Critical

The system shall support granular permissions for admin actions.

### **Permission Groups**

**Priority:** High

Permissions shall be organized by platform domain.

### **Sensitive Permission Control**

**Priority:** Critical

Sensitive permissions shall require explicit assignment.

### **Financial Permission Control**

**Priority:** Critical

Financial permissions shall be restricted to authorized finance or super admin roles.

### **Report Permission Control**

**Priority:** High

Report access and export permissions shall be separately controllable.

## **Admin Panel Access**

### **Admin Panel Access Restriction**

**Priority:** Critical

Only active authorized admin users shall access the admin panel.

### **Suspended Admin Access Block**

**Priority:** Critical

Suspended or deactivated admin users shall be prevented from accessing admin areas.

### **Admin Route Authorization**

**Priority:** Critical

Admin routes and actions shall enforce permission checks.

## **Sensitive Actions**

### **Sensitive Action Confirmation**

**Priority:** High

The system shall support additional confirmation for sensitive admin actions.

### **Sensitive Action Reason**

**Priority:** High

The system shall require reason for selected sensitive actions.

### **Financial Action Reason**

**Priority:** Critical

Manual financial actions shall require a reason.

## **Activity & Audit**

### **Admin Activity Logging**

**Priority:** Critical

The system shall log important admin actions.

### **Role Change Audit Log**

**Priority:** Critical

The system shall audit log role assignment and permission changes.

### **Admin Login Log**

**Priority:** High

The system shall log admin login activity.

### **Admin Export Log**

**Priority:** Critical

Sensitive report and data exports shall be audit logged.

## **Admin Notes**

### **Internal Admin Notes**

**Priority:** Medium

The system shall allow authorized administrators to add internal notes to supported records.

### **Admin Note Privacy**

**Priority:** Critical

Internal admin notes shall not be publicly visible to students or instructors.

## **Access Review**

### **Admin Access Review Report**

**Priority:** Medium

The system shall provide reports showing admin users, roles, permissions, sensitive access, and last login.

### **Inactive Admin Detection**

**Priority:** Medium

The system shall support identifying admin accounts inactive for a configured period.

## **Future Security**

### **Two-Factor Authentication Readiness**

**Priority:** Medium

The system shall be designed to support two-factor authentication for admin users.

### **Impersonation Restriction**

**Priority:** High

The system shall not allow user impersonation in Version 1 unless explicitly enabled with strict controls.

# **23.33 Business Rules**

- Admin access shall follow least privilege.
- Only active authorized admin users may access the admin panel.
- Students and instructors shall not access internal admin areas.
- Suspended, deactivated, or archived admins cannot access the admin panel.
- Sensitive permissions require explicit assignment.
- Financial permissions shall be restricted to authorized roles.
- Manual financial actions require reason and audit log.
- Role and permission changes must be audit logged.
- Admin accounts should not be physically deleted if referenced by historical logs.
- Admin users should not share accounts.
- Super Admin access should be limited to trusted owner-level users.
- Internal admin notes shall never be public.
- Sensitive exports require permission and audit log.
- No admin should be able to bypass payment, wallet, booking, or audit rules through UI access alone.
- User impersonation is not supported in Version 1 by default.

# **23.34 Validation Rules**

- Admin email must be unique.
- Admin role is required before operational access is granted.
- Suspended admin cannot perform actions.
- Role name must be unique.
- Permission key must be unique.
- Sensitive action reason is required where configured.
- Financial adjustment action requires financial permission.
- Withdrawal approval requires withdrawal approval permission.
- Report export requires export permission.
- Admin cannot deactivate the last active Super Admin without safeguard.
- Admin invitation token must be valid and unexpired.
- Internal admin note must be linked to a valid record.

# **23.35 User Workflows**

## **Super Admin Creates Admin User**

- Super Admin opens Admin Users.
- Super Admin creates new admin invitation.
- Super Admin enters name and email.
- Super Admin assigns role.
- System validates permissions.
- Invitation email is sent.
- Invitee sets password.
- Admin account becomes active.
- Action is audit logged.

## **Admin Role Assignment**

- Authorized admin opens admin user profile.
- Admin selects role.
- System validates role assignment permission.
- Admin confirms change.
- Role is assigned.
- Role change is audit logged.
- Affected admin access updates.

## **Finance Admin Approves Withdrawal**

- Finance Admin opens withdrawal request.
- System verifies withdrawal approval permission.
- Finance Admin reviews request.
- Finance Admin approves or rejects.
- Reason is recorded where required.
- Action is audit logged.
- Instructor is notified.

## **CMS Editor Publishes Page**

- CMS Editor opens CMS page.
- CMS Editor edits content.
- System verifies publish permission.
- CMS Editor publishes page.
- Page becomes public.
- Publishing action is audit logged.

## **Support Agent Adds Internal Note**

- Support Agent opens booking or support case.
- Support Agent adds internal note.
- System validates note permission.
- Note is saved.
- Note is visible only to authorized admins.
- Action is logged.

## **Admin Deactivation**

- Super Admin opens admin user profile.
- Super Admin selects deactivate.
- System checks whether admin is last Super Admin.
- Super Admin confirms action.
- Admin status becomes deactivated.
- Active sessions are revoked.
- Audit log is created.

## **Sensitive Report Export**

- Authorized admin opens report.
- Admin applies filters.
- Admin clicks export.
- System verifies export permission.
- System logs export request.
- Export file is generated.
- Admin downloads file.

# **23.36 Exception Handling**

## **Unauthorized Admin Action**

The system shall deny the action and may log the attempt.

## **Last Super Admin Deactivation**

The system shall block deactivation to prevent platform lockout.

## **Expired Admin Invitation**

The invitee shall be shown an expired invitation message, and an authorized admin may resend invitation.

## **Sensitive Permission Missing**

The system shall hide or disable the action and show access restricted message where appropriate.

## **Financial Action Without Reason**

The system shall block the action until reason is provided.

## **Suspended Admin Attempts Login**

The system shall deny login and log the attempt.

## **Export Failure**

The system shall show a safe error message and log the failure.

# **23.37 Notifications**

Admin access notifications may include:

## **Admin**

- Invitation received
- Role changed
- Account activated
- Account suspended
- Account deactivated
- Password reset
- Sensitive action completed
- Export completed

## **Super Admin**

- New admin created
- Admin role changed
- Financial permission assigned
- Security setting changed
- Admin deactivated
- Suspicious admin login, future
- Sensitive export performed

# **23.38 Reports & Analytics**

Admin access reports may include:

- Active admin users
- Suspended admins
- Deactivated admins
- Role assignment summary
- Sensitive permission users
- Financial permission users
- Last login report
- Admin activity report
- Export activity report
- Role change history
- Permission change history
- Failed admin login attempts
- Inactive admin accounts

# **23.39 Administrative Configuration**

Administrators with appropriate permission may configure:

- Standard roles
- Custom roles
- Permission groups
- Sensitive permissions
- Admin invitation expiry
- Admin session timeout
- Password policy
- Two-factor requirement, future
- Sensitive action confirmation rules
- Admin note permissions
- Export permissions
- Access review frequency, future

# **23.40 Acceptance Criteria**

- Only authorized active admin users can access the admin panel.
- Admin users can be assigned roles and permissions according to operational responsibilities.
- Sensitive financial actions require explicit permission, reason, and audit logging.
- Suspended, deactivated, and archived admin users cannot access admin areas.
- Role and permission changes are audit logged.
- Reports, settings, CMS, booking, wallet, payment, and support access are permission-controlled.
- Internal admin notes remain private and visible only to authorized admin users.
- Sensitive exports require export permission and are audit logged.
- The system prevents deactivation of the last active Super Admin.
- Admin access reports show roles, permissions, sensitive access, and last login information.

# **23.41 Future Enhancements**

This module is designed to support future expansion, including:

- Mandatory two-factor authentication
- Admin IP restrictions
- Device trust management
- Admin session recording, where legally appropriate
- Approval workflow for sensitive actions
- Four-eyes approval for financial changes
- Record-level permissions
- Country-level admin access
- Department/team-based access
- Support agent assignment queues
- Admin impersonation with strict controls
- Just-in-time elevated access
- Temporary permission grants
- Automated access review
- Privileged access management
- Security anomaly detection
- Admin risk scoring
- SSO integration
- SCIM provisioning, future enterprise use

# **23.42 Chapter Summary**

The Admin User Management, Roles, Permissions & Access Control module defines the internal access control foundation of STEM Learning.

It ensures that administrators can manage the platform securely while limiting access according to role, permission, sensitivity, and operational responsibility.

By separating roles, permissions, sensitive actions, financial restrictions, admin lifecycle, internal notes, audit logs, and access reviews, the platform can safely scale its operations team without exposing critical business, financial, or privacy risks.

This module is essential for enterprise readiness, financial safety, internal accountability, and long-term operational control.

# **STEM Learning**

## **Enterprise Software Requirements Specification (SRS) v2.0**

# **BOOK 2 - FUNCTIONAL REQUIREMENTS**

# **PART F - PLATFORM ADMINISTRATION & OPERATIONS**

# **CHAPTER 24 - ACTIVITY LOGS, AUDIT TRAIL & COMPLIANCE MONITORING**

# **24.1 Introduction**

The Activity Logs, Audit Trail & Compliance Monitoring module defines how STEM Learning records, stores, reviews, filters, and monitors important user actions, admin actions, financial events, security events, configuration changes, booking lifecycle events, instructor verification actions, support actions, and compliance-sensitive activities.

Activity logs and audit trails are essential for enterprise trust, financial safety, internal accountability, dispute resolution, fraud investigation, operational troubleshooting, and compliance readiness.

This module ensures that critical platform actions are traceable and attributable to the correct user, system process, administrator, or automated job.

# **24.2 Purpose**

The purpose of this module is to provide a reliable evidence and monitoring layer across the platform.

The module must ensure:

- Important actions are logged.
- Sensitive actions are auditable.
- Admin actions are attributable.
- Financial changes are traceable.
- Booking lifecycle history is preserved.
- Settings changes maintain previous and new values.
- Compliance-sensitive activity can be reviewed.
- Suspicious behavior can be detected.
- Logs are searchable by authorized users.
- Historical evidence is preserved according to retention policy.

# **24.3 Business Objectives**

This module shall support the following business objectives:

- Maintain accountability across the platform.
- Support financial audits.
- Support booking and refund dispute resolution.
- Detect suspicious or abusive activity.
- Track admin actions and sensitive changes.
- Support compliance and governance.
- Improve operational troubleshooting.
- Protect students, instructors, and the business.
- Reduce risk from unauthorized changes.
- Preserve historical evidence for future review.

# **24.4 Scope**

This chapter covers:

## **Activity Logs**

- User activity
- Student activity
- Instructor activity
- Admin activity
- System activity
- Automated job activity

## **Audit Trail**

- Financial audit
- Booking audit
- Settings audit
- Admin access audit
- Instructor verification audit
- CMS audit
- Report export audit

## **Compliance Monitoring**

- Suspicious activity detection
- Sensitive action monitoring
- Failed login monitoring
- Financial anomaly monitoring
- Data export monitoring
- Policy violation tracking

## **Administration**

- Audit log viewer
- Filters
- Search
- Export
- Retention rules
- Access permissions
- Compliance reports

# **24.5 Audit Philosophy**

The platform shall follow this principle:

Every sensitive action must answer: who did what, when, where, why, and what changed.

Audit records should help answer:

- Who performed the action?
- What action was performed?
- Which record was affected?
- When did it happen?
- What was the previous value?
- What is the new value?
- Why was it done?
- Was it user-driven or system-driven?
- Was the action successful or failed?

# **24.6 Activity Log vs Audit Trail**

The system should distinguish between general activity logs and formal audit trails.

## **Activity Log**

Records general user or system events.

Examples:

- Student viewed booking.
- Instructor updated availability.
- Notification sent.
- Homework submitted.
- User logged in.

## **Audit Trail**

Records sensitive, business-critical, financial, administrative, or compliance-related events.

Examples:

- Admin manually credited wallet.
- Payment verification retried.
- Instructor withdrawal approved.
- Booking refund overridden.
- Admin role changed.
- Payment setting updated.
- Instructor KYC rejected.
- Report exported.

Audit trail records require stricter retention, permission control, and reviewability.

# **24.7 Log Actor Types**

Every log should identify the actor.

Actor types may include:

## **Student**

A student user performed the action.

## **Instructor**

An instructor user performed the action.

## **Administrator**

An internal admin user performed the action.

## **System**

The platform automatically performed the action.

## **Scheduled Job**

A background job or scheduled task performed the action.

## **External Provider**

An external service caused or triggered an event.

Examples:

- Razorpay callback
- Meeting provider webhook
- Email provider delivery webhook

# **24.8 Log Subject Types**

Audit logs may apply to many subject types.

Examples:

- User
- Student profile
- Instructor profile
- Instructor verification document
- Booking
- Meeting
- Wallet
- Wallet transaction
- Payment
- Invoice
- Refund
- Instructor earning
- Withdrawal
- Referral reward
- Review
- Message
- CMS page
- Setting
- Country
- Report export
- Support case
- Notification
- Admin role
- Permission

The log should identify the affected record wherever possible.

# **24.9 Core Log Data**

Each activity or audit log may include:

- Log ID
- Event type
- Event category
- Actor type
- Actor ID
- Actor name snapshot
- Subject type
- Subject ID
- Action performed
- Description
- Previous values
- New values
- IP address
- User agent
- Device/browser information, optional
- Request ID or correlation ID
- Related booking/payment/wallet reference, where applicable
- Reason, where required
- Status
- Timestamp
- Severity level

# **24.10 Event Categories**

Audit and activity events should be categorized.

Recommended categories:

- Authentication
- Authorization
- Admin Access
- Student
- Instructor
- Instructor Verification
- Academic
- Learning Plan
- Homework
- Marketplace
- Availability
- Booking
- Meeting
- Wallet
- Payment
- Instructor Earnings
- Withdrawals
- Referral
- Reviews
- Messaging
- Notifications
- CMS
- Settings
- Country/Localization
- Reports
- Support
- Security
- System Jobs
- Compliance

# **24.11 Severity Levels**

Logs may use severity levels.

## **Info**

Normal activity.

Example:

- Student logged in.
- Booking viewed.

## **Notice**

Important but expected action.

Example:

- Booking rescheduled.
- Homework submitted.

## **Warning**

Potentially risky or unusual event.

Example:

- Failed payment.
- Repeated login failure.
- Instructor changed availability affecting bookings.

## **Critical**

High-impact sensitive action.

Example:

- Wallet manual debit.
- Withdrawal approved.
- Payment configuration changed.
- Admin role changed.

## **Security**

Security-related event.

Example:

- Suspicious login attempt.
- Unauthorized access attempt.
- Permission denied for sensitive action.

# **24.12 Authentication Logs**

Authentication logs may include:

- Login success
- Login failure
- Logout
- Password reset requested
- Password changed
- Email verification sent
- Email verified
- Account locked, future
- Session expired
- Suspicious login, future
- Admin login
- Admin failed login

Authentication logs support security monitoring.

# **24.13 Authorization Logs**

Authorization logs may include:

- Permission denied
- Unauthorized admin action attempt
- Restricted report access attempt
- Suspended account access attempt
- Role-based access denial
- Sensitive action blocked

Authorization logs are important for detecting misuse or misconfigured permissions.

# **24.14 Admin Action Logs**

Admin action logs shall record important internal actions.

Examples:

- Admin user created
- Admin role assigned
- Permission changed
- Student account suspended
- Instructor approved
- Booking overridden
- Wallet adjusted
- Withdrawal approved
- CMS page published
- Setting changed
- Report exported
- Message report reviewed
- Review hidden
- Support case updated

Admin action logs must be attributable to the specific admin user.

# **24.15 Financial Audit Logs**

Financial audit logs are critical.

They shall include:

- Wallet recharge credited
- Wallet debit for booking
- Wallet refund credited
- Manual wallet credit
- Manual wallet debit
- Payment verification
- Payment failure
- Razorpay callback received
- Duplicate callback ignored
- Payment mismatch detected
- Instructor earning created
- Instructor earning adjusted
- Instructor earning placed on hold
- Withdrawal requested
- Withdrawal approved
- Withdrawal rejected
- Withdrawal marked paid
- Referral reward credited
- Promotional credit issued

Financial audit logs must be protected from unauthorized access.

# **24.16 Booking Audit Logs**

Booking audit logs shall include:

- Slot selected
- Slot reserved
- Reservation expired
- Booking confirmed
- Payment linked
- Meeting created
- Booking cancelled
- Refund credited
- Booking rescheduled
- Student no-show marked
- Instructor no-show marked
- Technical issue reported
- Lesson completed
- Auto-completion executed
- Booking override performed

Booking logs support dispute resolution.

# **24.17 Meeting Audit Logs**

Meeting audit logs may include:

- Meeting created
- Meeting creation failed
- Meeting updated
- Meeting cancelled
- Student joined
- Instructor joined
- Admin observer joined
- Recording started, if available
- Recording stored
- Recording access granted
- Technical issue reported
- Attendance data received

Meeting logs support no-show, attendance, and quality review.

# **24.18 Instructor Verification Audit Logs**

Instructor verification logs shall include:

- Application submitted
- Document uploaded
- Document reviewed
- Document approved
- Document rejected
- Additional documents requested
- Instructor approved
- Instructor rejected
- Instructor suspended
- Instructor reactivated
- Verification status changed
- Admin verification note added

Verification audit history protects marketplace trust.

# **24.19 Settings Audit Logs**

Settings audit logs shall include:

- Setting key
- Previous value
- New value
- Changed by
- Changed at
- Reason, where required
- Impact level
- Module
- Country override, if applicable

Settings audit logs are mandatory for:

- Payment settings
- Wallet settings
- Booking settings
- Refund rules
- Instructor pay settings
- Security settings
- Meeting settings
- Referral reward settings
- Review settings
- Feature flags

# **24.20 CMS Audit Logs**

CMS audit logs may include:

- Page created
- Page updated
- Page published
- Page unpublished
- Page archived
- Legal page updated
- Blog post published
- FAQ updated
- Redirect created
- Robots.txt changed
- Sitemap generation failed
- Media uploaded
- Media deleted

Legal page updates should be especially traceable.

# **24.21 Report Export Audit Logs**

Report export logs shall include:

- Admin user
- Report type
- Filters applied
- Export format
- Export timestamp
- Record count, where available
- Sensitive data included flag
- IP address
- Reason, where required

Sensitive exports should be reviewable by Super Admin or Compliance roles.

# **24.22 Messaging and Communication Audit Logs**

Communication-related logs may include:

- Notification sent
- Notification failed
- Template updated
- Message sent
- Message reported
- Admin reviewed message
- Messaging access restricted
- WhatsApp provider failure
- Email delivery failure
- SMS delivery failure

Reported messages and admin reviews must be auditable.

# **24.23 Support and Dispute Audit Logs**

Support-related audit logs may include:

- Support case created
- Case assigned
- Case status changed
- Internal note added
- Refund dispute opened
- Technical issue reviewed
- No-show dispute reviewed
- Case escalated
- Case resolved
- Admin override applied

Support logs help reconstruct operational decisions.

# **24.24 System Job Logs**

System job logs may include:

- Reminder job sent
- Wallet deduction job executed
- Recurring booking job processed
- Meeting creation retry
- Recording fetch job
- Report generation job
- Sitemap generation job
- Notification retry job
- Failed job recorded
- Scheduled job completed

System job logs help with operational troubleshooting.

# **24.25 Compliance Monitoring**

Compliance monitoring detects or surfaces events requiring review.

Examples:

- Repeated failed admin logins
- Unauthorized access attempts
- High-value wallet adjustments
- Repeated refunds
- Suspicious referral activity
- Large report export
- Admin role change
- Payment mismatch
- Multiple instructor no-shows
- Repeated student no-shows
- Excessive booking cancellations
- Sensitive setting changes
- Unusual withdrawal pattern

Compliance monitoring should alert authorized roles.

# **24.26 Suspicious Activity Flags**

The platform may flag suspicious activity.

Possible flags:

- Many failed login attempts
- Multiple accounts using same referral pattern
- Repeated wallet refunds
- Repeated payment failures
- Unusual manual adjustments
- Instructor repeatedly cancelling lessons
- Student repeatedly no-showing
- Same admin performing unusual financial actions
- Large data export
- Rapid role permission changes

Version 1 may start with rule-based flags.

Future versions may support risk scoring.

# **24.27 Audit Log Search**

Authorized users should be able to search audit logs.

Search/filter options may include:

- Date range
- Actor
- Actor type
- Subject type
- Subject ID
- Event category
- Event type
- Severity
- IP address
- Related booking
- Related payment
- Related wallet transaction
- Related admin
- Status

Search permissions should be strict.

# **24.28 Audit Log Viewer**

The audit log viewer should display:

- Event timestamp
- Actor
- Action
- Subject
- Category
- Severity
- Description
- Previous values
- New values
- Reason
- IP/device details
- Related links
- Status

Sensitive values should be masked.

# **24.29 Audit Log Export**

Audit logs may be exported by authorized roles.

Rules:

- Export requires permission.
- Sensitive exports are logged.
- Export may be limited by date range.
- Export may be limited by record count.
- Financial audit exports require finance/compliance permission.
- Security logs require security/compliance permission.

# **24.30 Audit Log Retention**

Audit logs should follow retention policy.

Examples:

- Financial audit logs: long-term retention
- Admin action logs: long-term retention
- Authentication logs: configured retention
- Notification logs: shorter retention may be acceptable
- System job logs: operational retention
- Legal page update logs: long-term retention

Retention periods should be configurable but must not violate business or legal requirements.

# **24.31 Immutable Audit Records**

Critical audit records should not be editable.

If correction or explanation is required:

- Add a follow-up note.
- Add a linked correction event.
- Do not edit the original audit entry.

This preserves evidence integrity.

# **24.32 Privacy and Sensitive Data in Logs**

Logs must avoid unnecessary sensitive data.

Rules:

- Do not store passwords.
- Do not store full payment credentials.
- Mask API keys and secrets.
- Mask bank account details.
- Avoid storing full identity documents.
- Avoid unnecessary personal data.
- Store references instead of raw sensitive files.
- Restrict access to sensitive logs.

Audit value should be balanced with privacy protection.

# **24.33 Correlation ID**

The system should use correlation IDs for tracing related events.

Example:

A paid booking may generate:

- Booking reservation log
- Payment initiation log
- Razorpay callback log
- Wallet/payment log
- Booking confirmation log
- Meeting creation log
- Notification log

A shared correlation ID helps reconstruct the full flow.

# **24.34 Audit Dashboard**

The platform may provide an audit dashboard.

Dashboard sections may include:

- Critical events today
- Failed login attempts
- Sensitive financial actions
- Recent settings changes
- Recent report exports
- Suspicious activity flags
- Admin role changes
- Payment mismatches
- Failed system jobs

# **24.35 Compliance Review Workflow**

Suspicious or critical audit events may require review.

Workflow:

Event Flagged

│

▼

Compliance Review Created

│

▼

Assigned to Authorized Admin

│

▼

Reviewed

│

▼

Action Taken

│

▼

Closed

Version 1 may log and alert; full compliance case workflow may be future scope.

# **24.36 Functional Requirements**

## **Activity Logging**

### **Activity Log Creation**

**Priority:** Critical

The system shall create activity logs for defined user, admin, and system events.

### **Audit Trail Creation**

**Priority:** Critical

The system shall create audit trail records for sensitive and business-critical actions.

### **Actor Identification**

**Priority:** Critical

Every log shall identify the actor where available.

### **Subject Identification**

**Priority:** High

Every log shall identify the affected subject record where available.

### **Timestamp Recording**

**Priority:** Critical

Every log shall record accurate timestamp.

### **IP and User Agent Recording**

**Priority:** High

The system shall record IP address and user agent for security-sensitive actions where available.

## **Admin Audit**

### **Admin Action Logging**

**Priority:** Critical

The system shall log important admin actions.

### **Role and Permission Audit**

**Priority:** Critical

The system shall audit log admin role and permission changes.

### **Admin Login Audit**

**Priority:** High

The system shall log admin login success and failure events.

## **Financial Audit**

### **Wallet Audit**

**Priority:** Critical

The system shall audit log wallet credits, debits, refunds, reversals, and manual adjustments.

### **Payment Audit**

**Priority:** Critical

The system shall audit log payment initiation, verification, failure, mismatch, callback, and duplicate callback events.

### **Instructor Earnings Audit**

**Priority:** Critical

The system shall audit log instructor earning creation, holds, adjustments, reversals, settlements, and withdrawals.

### **Financial Reason Requirement**

**Priority:** Critical

Manual financial actions shall require a reason in the audit record.

## **Booking & Meeting Audit**

### **Booking Audit Trail**

**Priority:** Critical

The system shall maintain booking lifecycle audit history.

### **Meeting Audit Trail**

**Priority:** High

The system shall log meeting creation, update, cancellation, attendance, observer access, and failures.

### **No-Show Audit**

**Priority:** High

The system shall audit log student no-show, instructor no-show, and missed lesson decisions.

## **Settings & CMS Audit**

### **Settings Change Audit**

**Priority:** Critical

The system shall audit log high-impact settings changes with previous and new values.

### **Feature Flag Audit**

**Priority:** High

The system shall audit log feature flag changes.

### **CMS Audit**

**Priority:** High

The system shall audit log CMS creation, updates, publishing, archiving, redirects, and legal page changes.

## **Reports & Exports**

### **Report Export Audit**

**Priority:** Critical

The system shall audit log sensitive report exports.

### **Data Export Audit**

**Priority:** Critical

The system shall audit log personal, financial, or sensitive data exports.

## **Security & Compliance**

### **Unauthorized Access Logging**

**Priority:** High

The system shall log unauthorized access attempts for sensitive areas.

### **Suspicious Activity Flagging**

**Priority:** High

The system shall flag suspicious activity based on configured rules.

### **Compliance Alert**

**Priority:** High

The system shall alert authorized administrators for critical audit events.

### **Compliance Review Status**

**Priority:** Medium

The system may support review status for flagged compliance events.

## **Search & Viewer**

### **Audit Log Viewer**

**Priority:** High

Authorized users shall be able to view audit logs.

### **Audit Log Filtering**

**Priority:** High

Audit logs shall support filtering by date, actor, subject, category, severity, and event type.

### **Audit Log Search**

**Priority:** Medium

Audit logs shall support search by relevant reference IDs and descriptions.

### **Audit Export**

**Priority:** Medium

Authorized users shall be able to export audit logs where permitted.

## **Data Protection**

### **Sensitive Data Masking**

**Priority:** Critical

Audit logs shall mask sensitive values such as credentials, payment secrets, and bank details.

### **Immutable Critical Logs**

**Priority:** Critical

Critical audit records shall not be editable after creation.

### **Retention Policy**

**Priority:** High

The system shall support audit log retention policies.

### **Correlation ID**

**Priority:** Medium

The system shall support correlation IDs for tracing related events across workflows.

# **24.37 Business Rules**

Sensitive actions must create audit records.

Admin actions affecting money, permissions, settings, users, bookings, or public content must be audit logged.

Manual financial actions require reason.

Critical audit records shall be immutable.

Corrections to audit records shall be added as new linked entries, not edits to original records.

Audit log access shall be permission-controlled.

Sensitive log values shall be masked.

Audit logs shall not store passwords, full secrets, or raw sensitive credentials.

Financial audit logs shall be retained according to long-term retention policy.

Report exports containing sensitive data must be audit logged.

Suspicious activity flags shall be reviewable by authorized administrators.

System-generated events must identify system or job actor clearly.

External provider events must store provider reference where available.

Historical audit logs shall remain available even if the related user or record is archived.

# **24.38 Validation Rules**

- Audit event type is required.
- Audit category is required.
- Actor type is required where actor exists.
- Subject type and subject ID should be recorded where applicable.
- Timestamp is required.
- Manual financial audit event must include reason.
- Sensitive values must be masked before log storage or display.
- Audit export requires export permission.
- Audit retention rule must be valid.
- Correlation ID must be unique where generated for a workflow.

# **24.39 User Workflows**

## **Admin Performs Sensitive Financial Action**

- Admin opens student wallet.
- Admin initiates manual credit or debit.
- System verifies permission.
- Admin enters mandatory reason.
- System performs wallet transaction.
- Wallet ledger entry is created.
- Audit log is created with actor, action, amount, reason, and reference.
- Super Admin or Finance Admin can review the action later.

## **Settings Change Audit**

- Admin opens platform settings.
- Admin changes high-impact setting.
- System validates change.
- Admin confirms action.
- System stores previous and new value.
- Setting is updated.
- Audit log is created.
- Authorized users can review the setting history.

## **Booking Dispute Review**

- Student or instructor disputes a booking.
- Admin opens booking timeline.
- System displays booking audit events.
- Admin reviews reservation, payment, meeting, attendance, cancellation, and refund logs.
- Admin makes decision.
- Decision is recorded as audit event.

## **Report Export Audit**

- Admin opens financial report.
- Admin applies filters.
- Admin exports report.
- System verifies export permission.
- Export file is generated.
- Export action is audit logged with filters and timestamp.

## **Suspicious Referral Flag**

- System detects suspicious referral pattern.
- Referral reward is held.
- Suspicious activity flag is created.
- Admin receives alert.
- Admin reviews related logs.
- Admin approves or rejects reward.
- Decision is audit logged.

## **Admin Reviews Audit Log**

- Authorized admin opens audit log viewer.
- Admin applies filters.
- System displays matching audit entries.
- Admin opens event detail.
- Admin reviews actor, subject, previous values, new values, reason, and timestamp.
- Admin exports only if permitted.

# **24.40 Exception Handling**

## **Audit Log Creation Failure**

For critical actions, the system should fail safely where possible.

Example:

- Manual wallet adjustment should not complete if audit logging fails.

For non-critical logs, the system may retry logging and notify administrators if failure persists.

## **Missing Actor**

If actor is unavailable, the system shall record actor as system, unknown, or external provider where appropriate.

## **Sensitive Data Detected**

The system shall mask or reject sensitive data before storing logs.

## **Audit Export Too Large**

The system may queue export and notify the user when ready.

## **Unauthorized Audit Access**

The system shall deny access and may log the attempt.

## **Historical Subject Deleted or Archived**

Audit log shall still display stored subject snapshot or reference.

# **24.41 Notifications**

Audit and compliance notifications may include:

## **Super Admin**

- Admin role changed
- Financial setting changed
- Payment setting changed
- Security setting changed
- High-value wallet adjustment
- Large report export
- Suspicious admin activity

## **Finance Admin**

- Payment mismatch detected
- Wallet credit failure
- High refund activity
- Withdrawal anomaly
- Instructor earning adjustment

## **Operations Admin**

- Repeated booking cancellations
- Meeting creation failures
- High no-show pattern
- Technical issue spike

## **Compliance/Audit Viewer**

- Suspicious activity flag
- Sensitive export
- Unauthorized access attempt
- Critical audit event

# **24.42 Reports & Analytics**

Audit and compliance reports may include:

- Admin activity report
- Financial audit report
- Wallet adjustment report
- Payment mismatch report
- Report export history
- Settings change history
- Security event report
- Failed login report
- Unauthorized access report
- Instructor verification audit
- Booking dispute audit
- CMS publishing audit
- Suspicious activity report
- Sensitive action report
- Compliance review status report

# **24.43 Administrative Configuration**

Administrators with proper permission may configure:

- Audit categories
- Events to log
- Sensitive event list
- High-impact event list
- Audit retention period
- Suspicious activity rules
- Compliance alert recipients
- Export permissions
- Sensitive data masking rules
- Critical log failure behavior
- Audit dashboard access
- Compliance review workflow, future

# **24.44 Acceptance Criteria**

- The system logs defined user, admin, system, and external provider events.
- Sensitive financial, booking, settings, role, permission, and export actions create audit records.
- Manual financial actions require reason and are traceable.
- Audit records identify actor, action, subject, timestamp, and related references where available.
- High-impact settings changes record previous and new values.
- Audit logs are searchable and filterable by authorized users.
- Sensitive values are masked in audit storage and display.
- Critical audit records are immutable.
- Suspicious activity can be flagged and reviewed by authorized administrators.
- Sensitive report exports are permission-controlled and audit logged.

# **24.45 Future Enhancements**

This module is designed to support future expansion, including:

- Compliance case management
- Automated audit reports
- Scheduled compliance review
- Risk scoring
- AI anomaly detection
- Admin behavior analytics
- Security information and event management integration
- Data retention automation
- Legal hold support
- Tamper-evident audit storage
- Separate audit database
- Long-term archive storage
- Advanced audit export
- User data access logs
- Privacy request tracking
- Regulatory compliance dashboards
- Four-eyes approval audit
- Admin session monitoring
- Real-time suspicious activity alerts

# **24.46 Chapter Summary**

The Activity Logs, Audit Trail & Compliance Monitoring module is the evidence and accountability layer of STEM Learning.

It ensures that important user actions, admin actions, financial events, bookings, meetings, settings changes, CMS updates, instructor verification actions, report exports, and security events are traceable and reviewable.

By separating general activity logs from formal audit trails, enforcing immutable records for sensitive actions, requiring reasons for financial changes, masking sensitive values, and enabling suspicious activity monitoring, STEM Learning becomes enterprise-ready for internal governance, financial safety, dispute resolution, and future compliance needs.

##

##

# **CHAPTER 25 - SUPPORT, DISPUTES & OPERATIONAL CASE MANAGEMENT**

# **25.1 Introduction**

The Support, Disputes & Operational Case Management module defines how STEM Learning receives, tracks, manages, escalates, resolves, and audits student, instructor, booking, payment, meeting, refund, wallet, review, and technical support issues.

As STEM Learning grows, support cannot rely only on email, WhatsApp, or informal admin notes. Every operational issue should be captured as a structured support case with status, category, priority, related records, internal notes, communication history, responsible owner, and resolution outcome.

This module enables the platform team to provide reliable support while preserving accountability, evidence, and operational history.

# **25.2 Purpose**

The purpose of this module is to provide a centralized support and dispute management system.

The module must ensure:

- Students and instructors can raise support issues.
- Admins can create internal cases.
- Cases are categorized and prioritized.
- Cases can be linked to bookings, payments, wallet transactions, meetings, reviews, or instructor profiles.
- Internal notes are preserved.
- Escalations are tracked.
- Dispute decisions are auditable.
- Financial resolutions are routed through wallet/payment/earning workflows.
- Support operations can be reported and improved.

# **25.3 Business Objectives**

This module shall support the following business objectives:

- Improve student and instructor support experience.
- Reduce unresolved operational issues.
- Improve booking dispute handling.
- Provide structured refund and no-show review.
- Support technical issue investigation.
- Preserve evidence for dispute resolution.
- Reduce manual communication chaos.
- Improve accountability across support teams.
- Track support workload and resolution time.
- Prepare for future support-agent workflows.

# **25.4 Scope**

This chapter covers:

## **Support Cases**

- Student support cases
- Instructor support cases
- Admin-created cases
- Internal operational cases
- Case categories
- Case priority
- Case assignment
- Case status lifecycle

## **Disputes**

- Booking disputes
- No-show disputes
- Refund disputes
- Payment disputes
- Wallet disputes
- Review disputes
- Instructor payout disputes
- Technical issue disputes

## **Operations**

- Internal notes
- Escalation
- Evidence review
- Case resolution
- SLA tracking, future
- Support reports
- Audit trail

# **25.5 Support Philosophy**

Support should be:

- Structured
- Trackable
- Evidence-based
- Timely
- Respectful
- Permission-controlled
- Auditable
- Linked to source records
- Helpful to both students and instructors

The recommended principle is:

Every operational issue should become a traceable support case, not an informal message.

# **25.6 Support Case Types**

The platform may support multiple case types.

## **Student Support Case**

Raised by or for a student.

Examples:

- Booking issue
- Wallet recharge issue
- Refund question
- Meeting link issue
- Instructor no-show
- Homework issue
- Review concern

## **Instructor Support Case**

Raised by or for an instructor.

Examples:

- Booking schedule issue
- Student no-show
- Withdrawal issue
- Earning issue
- Document verification issue
- Meeting access problem
- Review dispute

## **Admin Operational Case**

Created internally by administrators.

Examples:

- Payment mismatch investigation
- Suspicious referral review
- Instructor quality review
- Technical failure follow-up
- Meeting creation failure
- Reconciliation issue

## **Compliance Case, Future**

Used for formal compliance or risk investigation.

# **25.7 Case Categories**

Recommended case categories:

- Account
- Student Profile
- Instructor Profile
- Instructor Verification
- Booking
- Demo Lesson
- Paid Lesson
- Recurring Lesson
- Cancellation
- Reschedule
- No-Show
- Meeting / Virtual Classroom
- Recording
- Payment
- Wallet
- Refund
- Instructor Earnings
- Withdrawal
- Referral / Reward
- Review / Rating
- Homework / Resources
- Messaging / Communication
- Technical Issue
- Policy Violation
- CMS/Public Website
- Other

Categories help route cases to the correct team.

# **25.8 Case Priority**

Cases may have priority levels.

## **Critical**

Requires urgent action.

Examples:

- Payment captured but booking not confirmed
- Instructor no-show for live class
- Meeting not created before class
- Wallet debit without booking
- Security or abuse issue

## **High**

Important and time-sensitive.

Examples:

- Refund dispute
- Withdrawal issue
- Recurring class issue
- Student unable to join class

## **Medium**

Normal support issue.

Examples:

- Profile update request
- Homework clarification
- Review dispute

## **Low**

Non-urgent issue.

Examples:

- General question
- Feature suggestion
- Minor content correction

# **25.9 Case Status Lifecycle**

Support cases may follow this lifecycle:

Open

│

▼

Assigned

│

▼

In Review

│

▼

Waiting for User

│

▼

Escalated

│

▼

Resolved

│

▼

Closed

Additional statuses:

- Reopened
- Cancelled
- Duplicate
- On Hold

# **25.10 Case Status Definitions**

## **Open**

Case has been created but not yet assigned.

## **Assigned**

Case has been assigned to an admin or support agent.

## **In Review**

Case is being investigated.

## **Waiting for User**

Case requires response or document from student/instructor.

## **Escalated**

Case requires higher-level team or manager review.

## **Resolved**

A resolution has been provided.

## **Closed**

Case is completed and no further action is expected.

## **Reopened**

Case was previously closed but has been opened again.

## **Duplicate**

Case is related to an existing issue.

## **On Hold**

Case is paused due to external dependency.

# **25.11 Case Data Fields**

A support case may include:

- Case reference number
- Case type
- Category
- Subcategory
- Priority
- Status
- Created by
- Created for
- Assigned to
- Related student
- Related instructor
- Related booking
- Related payment
- Related wallet transaction
- Related withdrawal
- Related meeting
- Related review
- Subject/title
- Description
- Attachments
- Internal notes
- Public replies
- Resolution summary
- Resolution type
- Created date
- Updated date
- Closed date
- SLA due date, future

# **25.12 Case Reference Number**

Every case should have a unique reference number.

Example:

SUP-2026-000123

The reference number helps users and admins track support communication.

# **25.13 Case Creation Sources**

Cases may be created from:

- Student dashboard
- Instructor dashboard
- Admin panel
- Booking details page
- Payment details page
- Wallet transaction page
- Meeting issue report
- Review report
- Message report
- No-show dispute flow
- Email integration, future
- WhatsApp integration, future

Version 1 may begin with dashboard/admin-created cases.

# **25.14 Student Support Submission**

Students should be able to raise support requests for:

- Booking issue
- Payment issue
- Wallet issue
- Refund request
- Instructor no-show
- Meeting access issue
- Homework issue
- Review/report issue
- General help

The support form should encourage linking the issue to a related booking or transaction.

# **25.15 Instructor Support Submission**

Instructors should be able to raise support requests for:

- Student no-show
- Schedule issue
- Meeting issue
- Earning issue
- Withdrawal issue
- Profile approval issue
- Document verification issue
- Review dispute
- Technical issue

Instructor support should protect instructor operational continuity.

# **25.16 Admin-Created Case**

Admins should be able to create cases internally.

Examples:

- Payment mismatch found in report.
- Suspicious referral activity flagged.
- Meeting provider failure.
- Instructor repeated low rating.
- Student repeated no-show.
- Withdrawal requires manual verification.
- Wallet transaction needs investigation.

Admin-created cases support operational follow-up.

# **25.17 Case Assignment**

Cases may be assigned to:

- Individual admin user
- Support team
- Finance team
- Operations team
- Academic team
- Marketplace team
- Compliance team, future

Assignment should be permission-controlled.

# **25.18 Case Escalation**

Cases may be escalated when:

- Issue is not resolved within expected time.
- Financial decision is required.
- Policy decision is required.
- Technical investigation is required.
- User disputes first resolution.
- High-value transaction is involved.
- Legal/compliance concern exists.

Escalation should record reason and target team/user.

# **25.19 Internal Notes**

Admins may add internal notes to cases.

Internal notes may include:

- Investigation findings
- Call summary
- Payment reference review
- Booking timeline observations
- Refund recommendation
- Instructor response
- Admin decision rationale

Internal notes must never be visible to students or instructors unless explicitly converted into public response.

# **25.20 Public Replies**

Support cases may include public replies visible to the case requester.

Public replies should be:

- Professional
- Clear
- Respectful
- Actionable
- Policy-aligned
- Free from internal notes
- Free from sensitive internal data

# **25.21 Attachments**

Support cases may allow attachments.

Examples:

- Screenshot
- Payment proof
- Error screen
- Meeting issue screenshot
- Homework-related file
- Identity/document issue evidence, where appropriate

Attachments must follow file validation and security rules.

# **25.22 Booking Disputes**

Booking disputes may involve:

- Incorrect cancellation
- Reschedule disagreement
- Lesson not conducted
- Instructor late
- Student late
- Wrong lesson duration
- Technical issue
- Refund disagreement
- Recurring schedule issue

Booking disputes should display booking timeline, payment status, meeting logs, attendance, and communication history where permitted.

# **25.23 No-Show Disputes**

No-show disputes are high-impact because they affect refunds and instructor earnings.

No-show dispute evidence may include:

- Student join time
- Instructor join time
- Meeting attendance logs
- Grace period settings
- Messages sent
- Technical issue reports
- Booking status timeline
- Admin notes

Possible outcomes:

- Student no-show confirmed
- Instructor no-show confirmed
- Both absent
- Technical issue
- Reschedule granted
- Wallet refund approved
- Instructor earning approved/held/rejected

# **25.24 Refund Disputes**

Refund disputes may be raised when:

- Student believes refund is due.
- Booking was cancelled incorrectly.
- Instructor no-show occurred.
- Technical issue prevented lesson.
- Payment captured but lesson not confirmed.
- Admin policy review is required.

Refund decisions should trigger wallet credit or reversal through Wallet module, not informal adjustment.

# **25.25 Payment Disputes**

Payment disputes may include:

- Payment deducted but not reflected.
- Payment failed but bank debited.
- Duplicate payment.
- Payment mismatch.
- Razorpay callback issue.
- Invoice missing.
- Wallet recharge not credited.

Payment disputes should be linked to payment attempts and Razorpay references.

# **25.26 Wallet Disputes**

Wallet disputes may include:

- Wallet recharge not credited.
- Wallet debit incorrect.
- Refund missing.
- Referral reward not credited.
- Promotional credit issue.
- Manual adjustment question.
- Recurring deduction issue.

Wallet disputes should be linked to wallet ledger records.

# **25.27 Instructor Earnings Disputes**

Instructor earnings disputes may include:

- Lesson earning missing.
- Earning amount incorrect.
- Demo incentive missing.
- Student no-show compensation issue.
- Withdrawal amount mismatch.
- Settlement delay question.
- Earning hold dispute.

Earning disputes should be linked to lesson, earning, settlement, and withdrawal records.

# **25.28 Review Disputes**

Review disputes may include:

- Instructor reports review as unfair.
- Student wants review removed or corrected.
- Review contains personal information.
- Review includes abusive language.
- Review appears fake.
- Review violates policy.

Review disputes should route to review moderation workflow.

# **25.29 Messaging and Conduct Reports**

Users may report messages or conduct issues.

Examples:

- Off-platform solicitation
- Sharing phone/email
- Harassment
- Spam
- Inappropriate language
- Payment request outside platform
- Policy violation

Reported conduct should be reviewed by authorized administrators.

# **25.30 Technical Issue Cases**

Technical issue cases may involve:

- Meeting link not working
- Audio/video issue
- Recording issue
- Login issue
- Payment checkout issue
- File upload issue
- Notification not received
- Page error
- Browser/device issue

Technical cases may require system logs, screenshots, and provider status review.

# **25.31 Case Resolution Types**

Resolution types may include:

- Information provided
- Booking rescheduled
- Wallet refund credited
- Payment issue resolved
- Instructor earning adjusted
- Withdrawal issue resolved
- Review hidden
- Review retained
- Message/report action taken
- Technical issue resolved
- User advised
- Policy upheld
- Case rejected
- Duplicate closed
- Escalated externally

Resolution type helps reporting.

# **25.32 Case Closure**

A case may be closed when:

- User issue is resolved.
- User has been informed.
- Admin decision has been recorded.
- Related financial action is completed.
- Related dispute outcome is finalized.
- No response received within configured period, future.
- Duplicate case merged or closed.

Closure should include resolution summary.

# **25.33 Case Reopening**

A case may be reopened when:

- User provides new evidence.
- Resolution was incomplete.
- Related payment/refund failed.
- Admin decision requires review.
- Technical issue reoccurs.

Reopening should create audit history.

# **25.34 Case Merging and Duplicates**

Duplicate cases may be linked or merged.

Examples:

- Same student reports same booking issue twice.
- Multiple users report same meeting outage.
- Same payment issue appears across email and dashboard.

Merging should preserve original case references and history.

# **25.35 SLA and Response Time**

Version 1 may not require formal SLA, but the platform should be SLA-ready.

Future SLA metrics may include:

- First response time
- Resolution time
- Escalation time
- Overdue cases
- Priority-based SLA
- Team-level SLA performance

SLA readiness helps professional support operations.

# **25.36 Support Knowledge Base Integration**

Support cases may link to FAQs or help articles.

Examples:

- How to recharge wallet
- How to reschedule class
- What happens if instructor is absent
- How refund works
- How to join online class

This reduces repetitive support workload.

# **25.37 Support Visibility**

Case visibility should be controlled.

Students may see:

- Their own cases
- Public replies
- Attachments they uploaded
- Case status
- Resolution summary

Instructors may see:

- Their own cases
- Cases linked to their lessons where permitted
- Public replies
- Case status

Admins may see cases based on permission.

Internal notes are admin-only.

# **25.38 Case Audit Trail**

Support cases must maintain audit trail.

Events may include:

- Case created
- Case assigned
- Status changed
- Priority changed
- Internal note added
- Public reply added
- Attachment uploaded
- Case escalated
- Financial action linked
- Case resolved
- Case closed
- Case reopened

Audit trail supports accountability.

# **25.39 Support Dashboard**

Support dashboard may include:

- Open cases
- Assigned cases
- Escalated cases
- Critical cases
- Cases waiting for user
- Overdue cases, future
- Cases by category
- Cases by priority
- Cases by team
- Recent unresolved disputes
- Financial issue cases
- Meeting issue cases

# **25.40 Functional Requirements**

## **Case Creation**

### **Support Case Creation**

**Priority:** Critical

The system shall allow students, instructors, and authorized admins to create support cases.

### **Case Reference Number**

**Priority:** Critical

The system shall generate a unique reference number for every support case.

### **Case Category**

**Priority:** High

The system shall require support cases to be categorized.

### **Case Priority**

**Priority:** High

The system shall support priority assignment for cases.

### **Related Record Linkage**

**Priority:** Critical

Support cases shall be linkable to relevant records such as booking, payment, wallet transaction, meeting, review, withdrawal, or user profile.

## **Case Lifecycle**

### **Case Status Lifecycle**

**Priority:** Critical

The system shall support case statuses such as open, assigned, in review, waiting for user, escalated, resolved, closed, reopened, duplicate, and on hold.

### **Case Assignment**

**Priority:** High

The system shall allow authorized admins to assign cases to users or teams.

### **Case Escalation**

**Priority:** High

The system shall allow cases to be escalated with reason.

### **Case Resolution**

**Priority:** Critical

The system shall allow authorized admins to record case resolution and resolution type.

### **Case Closure**

**Priority:** Critical

The system shall allow cases to be closed after resolution.

### **Case Reopen**

**Priority:** Medium

The system shall allow reopening of cases where permitted.

## **Communication**

### **Public Case Replies**

**Priority:** High

The system shall allow authorized admins and users to exchange public case replies.

### **Internal Admin Notes**

**Priority:** Critical

The system shall allow authorized admins to add internal notes not visible to students or instructors.

### **Case Attachments**

**Priority:** Medium

The system shall support case attachments subject to file validation rules.

### **Case Notifications**

**Priority:** High

The system shall notify relevant users when case status, assignment, reply, or resolution changes.

## **Disputes**

### **Booking Dispute Case**

**Priority:** Critical

The system shall support booking-related dispute cases.

### **No-Show Dispute Case**

**Priority:** Critical

The system shall support student and instructor no-show dispute cases.

### **Refund Dispute Case**

**Priority:** Critical

The system shall support refund-related dispute cases.

### **Payment Dispute Case**

**Priority:** Critical

The system shall support payment-related dispute cases linked to payment records.

### **Wallet Dispute Case**

**Priority:** Critical

The system shall support wallet-related dispute cases linked to wallet ledger records.

### **Instructor Earning Dispute Case**

**Priority:** High

The system shall support instructor earning and withdrawal dispute cases.

### **Review Dispute Case**

**Priority:** High

The system shall support review/rating dispute cases linked to moderation workflow.

### **Technical Issue Case**

**Priority:** High

The system shall support technical issue cases for meeting, payment, upload, notification, and login issues.

## **Operational Actions**

### **Linked Financial Action**

**Priority:** Critical

Support case resolution may link to wallet refund, earning adjustment, withdrawal action, or payment review, but financial changes must be executed through the appropriate financial module.

### **Linked Booking Action**

**Priority:** High

Support case resolution may link to booking reschedule, cancellation, no-show decision, or technical issue outcome.

### **Linked Review Moderation Action**

**Priority:** Medium

Support case resolution may link to review moderation action.

## **Admin Tools**

### **Support Dashboard**

**Priority:** High

The system shall provide support dashboards showing cases by status, priority, category, and assignment.

### **Case Search**

**Priority:** High

The system shall allow authorized users to search support cases.

### **Case Filters**

**Priority:** High

The system shall support filters by status, category, priority, assigned user, date range, user, booking, and related record.

### **Case Merge or Duplicate Link**

**Priority:** Medium

The system shall support marking cases as duplicate or linking related cases.

### **Case Audit Trail**

**Priority:** Critical

The system shall maintain audit trail for support case actions.

### **Support Reports**

**Priority:** Medium

The system shall provide support reports by category, priority, status, resolution type, and response time.

# **25.41 Business Rules**

- Every support case must have a unique reference number.
- Support cases should be linked to relevant platform records whenever possible.
- Internal notes are never visible to students or instructors.
- Financial resolutions must be executed through financial modules, not informal case notes.
- Refund decisions must be linked to wallet/payment records.
- No-show dispute decisions must consider booking, meeting, attendance, and grace-period records.
- Case status changes must be audit logged.
- Escalation requires reason.
- Support access must be permission-controlled.
- Students may only view their own support cases.
- Instructors may only view their own support cases or cases related to their lessons where permitted.
- Support case attachments must follow file security rules.
- Closed cases may be reopened only according to configured policy.
- Support case resolution must include resolution summary.

# **25.42 Validation Rules**

- Case title or subject is required.
- Case category is required.
- Case priority is required or must be assigned by system default.
- Case status must be valid.
- Case reference number must be unique.
- Related booking must belong to the involved student or instructor unless admin-created.
- Financial dispute case must reference related payment, wallet transaction, booking, or withdrawal where available.
- Escalation requires escalation reason.
- Resolution requires resolution summary.
- Attachment file type must be allowed.
- Attachment file size must not exceed configured limit.
- Internal note cannot be exposed through public reply channel.

# **25.43 User Workflows**

## **Student Raises Booking Issue**

- Student opens booking details.
- Student selects "Need Help".
- Student chooses issue category.
- Student enters description and optional attachment.
- System creates support case linked to booking.
- Student receives case reference.
- Admin reviews case.
- Resolution is provided and case is closed.

## **Instructor Reports Student No-Show**

- Instructor opens lesson details.
- Instructor reports student no-show.
- System captures meeting attendance data where available.
- System creates no-show case or no-show review record.
- Operations admin reviews case.
- Admin confirms outcome.
- Related booking and earning rules are applied.
- Case is resolved and audit logged.

## **Student Requests Refund Review**

- Student opens booking or payment.
- Student selects refund issue.
- Student explains reason.
- System creates refund dispute case.
- Admin reviews booking, cancellation, payment, and meeting logs.
- Admin approves or rejects refund according to policy.
- Approved refund is processed through wallet module.
- Resolution is recorded.

## **Payment Issue Investigation**

- Student reports payment deducted but booking not confirmed.
- System creates payment dispute case.
- Finance admin reviews Razorpay reference and payment attempt.
- Admin reconciles payment status.
- Wallet or booking is updated through proper module if needed.
- Case resolution is recorded.

## **Support Agent Escalates Case**

- Support Agent opens assigned case.
- Agent reviews issue.
- Agent determines finance/operations/academic review is required.
- Agent escalates case with reason.
- System assigns to escalation team.
- Escalation is audit logged.
- Case continues under new owner/team.

## **Admin Adds Internal Note**

- Admin opens support case.
- Admin adds internal note.
- System marks note as internal.
- Note is visible only to authorized admins.
- Action is audit logged.

## **Case Closure**

- Admin resolves case.
- Admin selects resolution type.
- Admin enters resolution summary.
- System notifies user.
- Case status becomes resolved or closed.
- Case audit trail is preserved.

# **25.44 Exception Handling**

## **Case Without Related Record**

The system shall allow general support cases but encourage linking related records when applicable.

## **Unauthorized Case Access**

The system shall deny access and may log the attempt.

## **Financial Action Permission Missing**

The system shall prevent financial action and require escalation to authorized finance role.

## **Attachment Upload Failure**

The system shall preserve case draft content and show upload failure message.

## **Duplicate Case**

The admin may link or mark case as duplicate while preserving original case history.

## **User Stops Responding**

The case may move to waiting status and later close according to configured policy.

## **Linked Record Archived**

The case shall preserve historical references and snapshots where available.

# **25.45 Notifications**

Support notifications may include:

## **Student**

- Case created
- Admin replied
- Case status changed
- Case resolved
- Case closed
- Additional information requested

## **Instructor**

- Case created
- Admin replied
- No-show dispute update
- Earning dispute update
- Withdrawal issue update
- Case resolved

## **Admin**

- New critical case
- Case assigned
- Case escalated
- User replied
- Case reopened
- SLA overdue, future
- Financial dispute created
- No-show dispute created

# **25.46 Reports & Analytics**

Support reports may include:

- Total cases
- Open cases
- Closed cases
- Cases by category
- Cases by priority
- Cases by status
- Cases by user type
- Average first response time
- Average resolution time
- Escalation rate
- Reopen rate
- Refund dispute count
- Payment dispute count
- No-show dispute count
- Meeting issue count
- Instructor issue count
- Student issue count
- Cases by assigned admin
- Resolution type distribution
- SLA performance, future

# **25.47 Administrative Configuration**

Administrators shall be able to configure:

- Support categories
- Support subcategories
- Case priorities
- Case statuses
- Assignment teams
- Escalation rules
- Case notification templates
- Attachment file types
- Attachment size limits
- Auto-close rules, future
- SLA rules, future
- Support operating hours
- Resolution types
- Case access permissions
- Internal note permissions
- Public reply permissions

# **25.48 Acceptance Criteria**

- Students, instructors, and authorized admins can create support cases.
- Every support case has a unique reference number.
- Cases can be categorized, prioritized, assigned, escalated, resolved, and closed.
- Cases can be linked to bookings, payments, wallet transactions, meetings, withdrawals, reviews, and user profiles.
- Internal notes are visible only to authorized admins.
- Financial case resolutions are processed through wallet, payment, earning, or withdrawal modules.
- No-show disputes use booking, meeting, attendance, and policy evidence.
- Case actions are audit logged.
- Support dashboards and reports show case workload and resolution trends.
- Support permissions prevent unauthorized access to private or sensitive cases.

# **25.49 Future Enhancements**

This module is designed to support future expansion, including:

- Full helpdesk ticketing system
- Email-to-ticket integration
- WhatsApp-to-ticket integration
- Chatbot support
- AI ticket classification
- AI response suggestions
- SLA management
- Automated escalation
- Support macros/templates
- Customer satisfaction survey
- Support knowledge base
- Case assignment rules
- Case merging
- Parent support cases
- Corporate account support
- Compliance case management
- Legal hold on cases
- Multi-language support
- Support workload forecasting
- Voice/call logging
- Screen recording evidence for technical issues
- External support tool integration

# **25.50 Chapter Summary**

The Support, Disputes & Operational Case Management module provides a structured operational helpdesk layer for STEM Learning.

It ensures that student issues, instructor issues, booking disputes, no-show disputes, refund reviews, payment problems, wallet questions, meeting failures, review disputes, earning issues, and technical problems are managed through traceable support cases rather than informal communication.

By linking cases to platform records, preserving internal notes, enabling escalation, enforcing permission control, and maintaining audit history, STEM Learning can deliver professional support while protecting financial integrity, marketplace trust, and operational accountability.

##

##

# **CHAPTER 26 - SYSTEM HEALTH, QUEUES, JOBS & OPERATIONAL MONITORING**

# **26.1 Introduction**

The System Health, Queues, Jobs & Operational Monitoring module defines how STEM Learning monitors and manages the background processes, scheduled jobs, queues, provider callbacks, retries, failures, operational alerts, and health indicators required to keep the platform reliable.

STEM Learning depends heavily on asynchronous and scheduled operations such as booking reminders, meeting creation, Razorpay callbacks, wallet deductions, recurring lesson generation, notification delivery, invoice generation, instructor earning settlement, referral reward processing, report exports, sitemap generation, and system maintenance.

This module ensures that critical background workflows are visible, traceable, retryable, and operationally manageable.

# **26.2 Purpose**

The purpose of this module is to provide an operational reliability layer for STEM Learning.

The module must ensure:

- Background jobs are monitored.
- Failed jobs are visible and retryable.
- Scheduled tasks run reliably.
- Payment callbacks are tracked.
- Notification delivery failures are detected.
- Meeting creation failures are alerted.
- Recurring lesson jobs are monitored.
- Wallet deduction jobs are reliable.
- System health is visible to admins.
- Operational alerts reach the correct teams.
- Critical failures do not remain unnoticed.

# **26.3 Business Objectives**

This module shall support the following business objectives:

- Improve platform reliability.
- Reduce missed lessons caused by technical failures.
- Detect payment, wallet, and booking failures early.
- Prevent silent background job failures.
- Support operational troubleshooting.
- Improve admin visibility into system health.
- Support service recovery through retries.
- Reduce manual support load.
- Protect financial and booking integrity.
- Prepare the platform for scalable production operations.

# **26.4 Scope**

This chapter covers:

## **System Health**

- Application health
- Database health
- Queue health
- Scheduler health
- Cache health
- Storage health
- Provider health

## **Queues**

- Notification queues
- Payment processing queues
- Wallet queues
- Booking queues
- Meeting queues
- Report queues
- Media queues
- Email queues

## **Jobs**

- Scheduled jobs
- Recurring lesson jobs
- Reminder jobs
- Wallet deduction jobs
- Meeting creation jobs
- Notification jobs
- Settlement jobs
- Sitemap jobs
- Report export jobs

## **Monitoring**

- Failed jobs
- Retry attempts
- Job latency
- Queue size
- Provider failures
- Callback failures
- Admin alerts
- Operational dashboard

# **26.5 Operational Reliability Philosophy**

The platform shall follow this principle:

Critical background operations must be observable, retryable, and auditable.

The system should not silently fail when processing important actions such as:

- Payment confirmation
- Wallet credit/debit
- Booking confirmation
- Meeting creation
- Lesson reminders
- Recurring booking generation
- Instructor earnings
- Withdrawal processing
- Refund credits
- Notification delivery

# **26.6 Background Processing Strategy**

The platform shall use background jobs for tasks that should not block the user interface.

Examples:

- Sending email
- Sending WhatsApp messages
- Sending SMS
- Creating meeting links
- Generating invoices
- Processing reports
- Sending reminders
- Processing recurring deductions
- Generating sitemap
- Retrying provider failures

Background processing improves user experience and platform scalability.

# **26.7 Queue Categories**

Queues may be separated by business priority.

Recommended queues:

## **Critical Queue**

For urgent transactional operations.

Examples:

- Payment confirmation follow-up
- Wallet credit after payment
- Booking confirmation
- Meeting creation before lesson

## **Notifications Queue**

For email, SMS, WhatsApp, and in-app notifications.

## **Payments Queue**

For payment verification, callback processing, and reconciliation support.

## **Wallet Queue**

For wallet credits, debits, refunds, and recurring deductions.

## **Booking Queue**

For booking confirmation, recurring schedule generation, auto-completion, and no-show jobs.

## **Meetings Queue**

For meeting creation, updates, recording fetch, and attendance sync.

## **Reports Queue**

For large report exports and scheduled reports.

## **Low Priority Queue**

For sitemap, analytics snapshots, cleanup jobs, and non-urgent tasks.

# **26.8 Queue Priority**

The system should prioritize jobs based on business impact.

Highest priority:

- Payment confirmation
- Wallet credit/debit
- Booking confirmation
- Meeting creation
- Lesson starting soon reminders

Lower priority:

- Blog sitemap update
- Content analytics snapshots
- Non-critical reports
- Marketing notifications

Queue priority helps prevent low-value jobs from delaying critical operations.

# **26.9 Job Status Definitions**

Jobs may have statuses:

## **Pending**

Job is waiting to run.

## **Processing**

Job is currently running.

## **Completed**

Job finished successfully.

## **Failed**

Job failed after execution.

## **Retrying**

Job is waiting for another retry attempt.

## **Cancelled**

Job was cancelled.

## **Expired**

Job is no longer useful because the related time window passed.

# **26.10 Failed Job Management**

Failed jobs must be visible to authorized administrators or technical operators.

Failed job details may include:

- Job name
- Queue name
- Related module
- Related record
- Error message
- Retry count
- Last attempted time
- Next retry time
- Stack trace, technical role only
- Failure category
- Severity
- Suggested action, future

Failed jobs should be retryable where safe.

# **26.11 Retry Strategy**

Retry strategy should depend on job type.

## **Safe to Retry**

Examples:

- Email notification
- WhatsApp notification
- SMS notification
- Meeting creation
- Invoice generation
- Sitemap generation
- Report generation

## **Retry with Idempotency Required**

Examples:

- Wallet credit
- Wallet debit
- Payment confirmation
- Booking confirmation
- Instructor earning creation
- Referral reward credit

## **Do Not Retry After Expiry**

Examples:

- Lesson reminder after lesson has ended
- Meeting creation after lesson is completed or cancelled
- Expired booking reservation

Retry rules must prevent duplicate financial or booking effects.

# **26.12 Idempotency**

Critical jobs must be idempotent.

Idempotency means the same job can run more than once without causing duplicate effects.

Required for:

- Payment callback processing
- Wallet credit
- Wallet debit
- Booking confirmation
- Refund credit
- Referral reward credit
- Instructor earning creation
- Meeting creation
- Invoice generation

Example:

A Razorpay payment callback received twice must not credit the wallet twice.

# **26.13 Scheduled Tasks**

The platform shall support scheduled tasks.

Examples:

- Lesson reminders
- Recurring lesson generation
- Recurring wallet deduction
- Booking auto-completion
- No-show evaluation
- Instructor earning eligibility update
- Withdrawal settlement batch, future
- Referral reward processing
- Sitemap generation
- Report snapshots
- Data cleanup
- Failed notification retry
- Provider health checks

Scheduled tasks must be monitored.

# **26.14 Scheduler Health**

Scheduler health indicates whether scheduled jobs are running.

The system should detect:

- Scheduler not running
- Missed scheduled task
- Delayed task
- Repeated scheduler failure
- Long-running scheduled task
- Overlapping task issue

Admins or technical operators should be alerted when critical scheduled tasks fail.

# **26.15 Payment Callback Monitoring**

Razorpay callback/webhook processing is critical.

The system should monitor:

- Callback received
- Callback verified
- Signature validation
- Payment matched
- Payment mismatch
- Duplicate callback ignored
- Wallet credit processed
- Booking confirmation processed
- Callback failure
- Callback retry

Payment callback failures must generate alerts.

# **26.16 Meeting Provider Monitoring**

Meeting creation and access are critical for online lessons.

The system should monitor:

- Meeting creation request
- Meeting creation success
- Meeting creation failure
- Meeting update failure
- Meeting cancellation failure
- Meeting link missing before lesson
- Recording fetch failure
- Attendance sync failure
- Provider API failure
- Provider rate limit

If a meeting link is missing before a lesson, the system should escalate quickly.

# **26.17 Notification Provider Monitoring**

Notification delivery providers should be monitored.

Channels:

- Email
- SMS
- WhatsApp
- In-app notification

Monitoring should include:

- Sent count
- Failed count
- Provider error
- Retry count
- Critical notification failures
- Delivery delay
- Provider disabled
- Template error
- Invalid recipient

Critical notifications should alert administrators when failure rates exceed thresholds.

# **26.18 Wallet Job Monitoring**

Wallet operations are financially sensitive.

Monitor:

- Wallet recharge credit
- Wallet debit
- Wallet refund credit
- Recurring deduction
- Referral reward credit
- Promotional credit
- Manual adjustment
- Failed wallet job
- Duplicate transaction prevention
- Ledger mismatch

Wallet job failure should be treated as critical.

# **26.19 Recurring Lesson Job Monitoring**

Recurring lessons depend on scheduled jobs.

Monitor:

- Upcoming recurring occurrence generation
- Wallet deduction before occurrence
- Insufficient wallet balance
- Booking creation failure
- Instructor unavailable conflict
- Student notification failure
- Recurring schedule paused/cancelled
- Retry results

Recurring job failure can directly affect student learning continuity.

# **26.20 Booking Lifecycle Job Monitoring**

Booking-related jobs include:

- Reservation expiry
- Booking confirmation
- Meeting creation
- Lesson reminder
- Auto-completion
- No-show evaluation
- Review request
- Homework follow-up
- Settlement trigger

Each job should link to related booking and appear in booking timeline where useful.

# **26.21 Instructor Earnings Job Monitoring**

Instructor earning jobs include:

- Earning creation after lesson completion
- Earning hold
- Earning release
- Incentive calculation
- Settlement eligibility update
- Withdrawal status update
- Payout statement generation

Failures may affect instructor trust and must be visible to finance/admin teams.

# **26.22 Report Export Job Monitoring**

Large reports may be generated asynchronously.

Monitor:

- Report export requested
- Report generation started
- Report completed
- Report failed
- Export file generated
- Export expired
- Export downloaded
- Export audit logged

Sensitive report exports must be audit logged.

# **26.23 Sitemap and CMS Job Monitoring**

CMS-related background jobs may include:

- Sitemap generation
- Sitemap submission, future
- Scheduled content publish
- Scheduled content unpublish
- Redirect validation, future
- SEO metadata refresh, future

CMS job failures should alert CMS/admin users where appropriate.

# **26.24 File and Media Job Monitoring**

Media operations may include:

- Async upload processing
- Image optimization
- Thumbnail generation
- File virus scan, future
- File storage sync
- Failed upload cleanup

File processing failures should be visible in admin and user workflows.

# **26.25 System Health Dashboard**

The platform should provide a system health dashboard for authorized users.

Dashboard may include:

- Application status
- Queue status
- Scheduler status
- Failed jobs
- Pending jobs
- Long-running jobs
- Payment callback status
- Meeting provider status
- Notification provider status
- Storage status
- Cache status
- Database status
- Last successful scheduled task
- Critical alerts

Version 1 may include admin-visible operational summaries and rely on technical monitoring tools for deeper infrastructure details.

# **26.26 Health Status Levels**

System health may use status levels.

## **Healthy**

System is operating normally.

## **Degraded**

Some non-critical service or queue is delayed.

## **At Risk**

Important jobs are delayed or provider issues are detected.

## **Critical**

A core platform function is failing.

## **Maintenance**

System is under planned maintenance.

# **26.27 Operational Alerts**

Alerts should be generated for important failures.

Examples:

- Payment callback failure
- Wallet credit failure
- Booking confirmation failure
- Meeting link missing
- Critical notification failure
- Scheduler not running
- Queue backlog too high
- Failed job threshold exceeded
- Razorpay provider issue
- Meeting provider issue
- Storage issue
- Report export failure
- Recurring deduction failure
- Instructor earning job failure

Alerts should go to the correct admin or technical team.

# **26.28 Alert Severity**

Alert severity may include:

## **Info**

Useful operational notice.

## **Warning**

Needs attention but not urgent.

## **High**

Important issue requiring action.

## **Critical**

Immediate action required.

Critical alerts may notify Super Admin, Operations Admin, Finance Admin, or technical team depending on module.

# **26.29 Alert Routing**

Alerts should route by module.

Examples:

- Payment alert → Finance Admin
- Wallet alert → Finance Admin
- Meeting alert → Operations Admin
- Booking alert → Operations Admin
- Notification alert → Operations/Admin
- CMS alert → CMS Manager
- Security alert → Super Admin
- Queue/scheduler alert → Technical Admin
- Instructor earning alert → Finance Admin

Alert routing should be configurable.

# **26.30 Provider Health Monitoring**

External providers must be monitored.

Provider categories:

- Payment gateway
- Email provider
- SMS provider
- WhatsApp provider
- Meeting provider
- Storage provider
- Analytics provider, future

Provider health may be based on:

- API failures
- Webhook failures
- Response time
- Rate limit errors
- Authentication errors
- Delivery failure rate
- Manual provider status checks, future

# **26.31 Infrastructure Health Readiness**

Although deep infrastructure monitoring may be technical implementation detail, the SRS should require readiness for:

- Server health
- Database connectivity
- Cache availability
- Queue worker availability
- Storage availability
- Disk usage
- CPU/memory, future
- SSL certificate validity, future
- Backup status, future

Operational monitoring should not depend only on user complaints.

# **26.32 Maintenance Mode**

The platform may support maintenance mode.

Maintenance mode should:

- Display friendly message to public users.
- Protect admin access where permitted.
- Pause non-critical jobs if required.
- Avoid payment collection during critical maintenance if necessary.
- Resume operations cleanly.

Maintenance mode activation should be audit logged.

# **26.33 Operational Runbooks**

For critical failures, admins or technical operators should have runbooks.

Runbook examples:

- Payment callback failure
- Wallet credit mismatch
- Meeting provider outage
- Queue backlog
- Scheduler stopped
- Failed recurring deduction
- Notification provider outage
- Report export failure

Version 1 may include documented SOPs outside the application; future versions may link runbooks from alerts.

# **26.34 Job Correlation**

Background jobs should link to related business records.

Examples:

- Payment job links to payment attempt.
- Wallet job links to wallet transaction.
- Booking job links to booking.
- Meeting job links to meeting.
- Notification job links to notification log.
- Report job links to export record.

This enables troubleshooting from the business record itself.

# **26.35 Job Expiry and Cancellation**

Some jobs become invalid after time or status changes.

Examples:

- Lesson reminder after cancellation
- Meeting creation after booking cancellation
- Reservation expiry after booking confirmation
- Review request after booking invalidation
- Notification after user status suspension

Jobs should check current record state before executing.

# **26.36 Functional Requirements**

## **System Health**

### **System Health Dashboard**

**Priority:** High

The system shall provide authorized users with system health and operational status visibility.

### **Health Status Levels**

**Priority:** Medium

The system shall support health status levels such as healthy, degraded, at risk, critical, and maintenance.

### **Provider Health Status**

**Priority:** High

The system shall monitor provider health for payment, meeting, notification, and storage services where applicable.

## **Queue Monitoring**

### **Queue Monitoring**

**Priority:** Critical

The system shall monitor queue size, failed jobs, pending jobs, and processing status.

### **Queue Priority Support**

**Priority:** High

The system shall support prioritization of critical jobs over low-priority jobs.

### **Failed Job Visibility**

**Priority:** Critical

Authorized users shall be able to view failed jobs.

### **Failed Job Retry**

**Priority:** High

Authorized users or system processes shall be able to retry failed jobs where safe.

### **Failed Job Classification**

**Priority:** Medium

Failed jobs should be classified by module, severity, and failure type.

## **Scheduled Jobs**

### **Scheduler Monitoring**

**Priority:** Critical

The system shall monitor whether scheduled tasks are running.

### **Missed Scheduled Task Detection**

**Priority:** High

The system shall detect missed or delayed critical scheduled tasks.

### **Scheduled Job History**

**Priority:** Medium

The system shall record scheduled job execution history for critical tasks.

## **Payments & Wallet**

### **Payment Callback Monitoring**

**Priority:** Critical

The system shall monitor Razorpay callback processing, verification, duplication, mismatch, and failure events.

### **Wallet Job Monitoring**

**Priority:** Critical

The system shall monitor wallet credit, debit, refund, recurring deduction, referral reward, and promotional credit jobs.

### **Financial Job Alert**

**Priority:** Critical

The system shall generate alerts for failed financial jobs.

## **Booking & Meetings**

### **Booking Job Monitoring**

**Priority:** Critical

The system shall monitor booking confirmation, reservation expiry, auto-completion, no-show, and review request jobs.

### **Recurring Lesson Job Monitoring**

**Priority:** Critical

The system shall monitor recurring booking generation and recurring wallet deduction jobs.

### **Meeting Creation Monitoring**

**Priority:** Critical

The system shall monitor meeting creation, update, cancellation, and failure events.

### **Missing Meeting Link Alert**

**Priority:** Critical

The system shall alert operations when an upcoming lesson has no meeting link within configured time.

## **Notifications**

### **Notification Job Monitoring**

**Priority:** High

The system shall monitor notification delivery jobs across email, SMS, WhatsApp, and in-app channels.

### **Critical Notification Failure Alert**

**Priority:** High

The system shall alert administrators when critical notification delivery fails or failure thresholds are exceeded.

## **Reports & CMS**

### **Report Export Job Monitoring**

**Priority:** Medium

The system shall monitor asynchronous report export jobs.

### **CMS Scheduled Job Monitoring**

**Priority:** Medium

The system shall monitor scheduled publishing, sitemap generation, and CMS-related jobs.

## **Job Safety**

### **Job Idempotency**

**Priority:** Critical

Critical background jobs shall be idempotent to prevent duplicate financial, booking, or notification effects.

### **Job State Validation**

**Priority:** Critical

Jobs shall validate current related record state before executing.

### **Job Expiry**

**Priority:** High

Time-sensitive jobs shall expire or cancel when no longer relevant.

### **Job Correlation**

**Priority:** Medium

Jobs shall be linked to related business records where applicable.

## **Alerts**

### **Operational Alert Creation**

**Priority:** Critical

The system shall create operational alerts for critical failures.

### **Alert Routing**

**Priority:** High

Operational alerts shall be routed to appropriate roles or teams.

### **Alert Status**

**Priority:** Medium

Alerts shall support status such as open, acknowledged, resolved, and ignored.

### **Alert Audit Trail**

**Priority:** High

Operational alert actions shall be audit logged.

## **Maintenance**

### **Maintenance Mode**

**Priority:** Medium

The system shall support maintenance mode or maintenance readiness.

### **Maintenance Audit Log**

**Priority:** High

Maintenance mode activation and deactivation shall be audit logged.

# **26.37 Business Rules**

- Critical background jobs must not fail silently.
- Critical jobs must be observable through logs, alerts, or dashboards.
- Financial jobs must be idempotent.
- Payment callbacks must not create duplicate wallet credits, booking confirmations, or invoices.
- Meeting creation failure before a lesson must alert operations.
- Failed wallet credit or debit jobs must alert finance/admin roles.
- Expired reminder jobs must not send late reminders.
- Low-priority jobs must not block critical booking, payment, wallet, or meeting jobs.
- Retry policies must not create duplicate financial or booking effects.
- Scheduled task failures must be detected.
- Critical operational alerts should remain open until acknowledged or resolved.
- Provider credential or authentication failures must alert authorized administrators.
- Maintenance mode changes must be audit logged.

# **26.38 Validation Rules**

- Job type must be valid.
- Queue name must be valid.
- Critical job must define idempotency key where applicable.
- Retry count must not exceed configured maximum.
- Expired jobs must not execute user-impacting action.
- Operational alert must have severity.
- Operational alert must have related module or category.
- Failed financial job must reference related business record where available.
- Payment callback must pass signature verification before financial action.
- Maintenance mode activation requires authorized permission.

# **26.39 User Workflows**

## **Failed Meeting Creation Alert**

- Booking is confirmed.
- Meeting creation job runs.
- Meeting provider returns failure.
- Job retries according to policy.
- Failure persists.
- System creates operational alert.
- Operations admin receives alert.
- Admin manually reviews booking and provider status.
- Meeting is created or alternative action is taken.
- Alert is resolved.

## **Razorpay Callback Processing**

- Razorpay sends payment callback.
- System validates callback signature.
- System checks idempotency key.
- System matches payment attempt.
- System updates payment status.
- Wallet or booking workflow continues.
- Audit log is created.
- Any mismatch creates alert.

## **Failed Wallet Credit**

- Payment is successful.
- Wallet credit job starts.
- Wallet credit fails.
- System does not mark transaction as completed.
- Failed job is logged.
- Finance alert is created.
- Job is retried safely.
- Wallet ledger is created after success.
- Alert is resolved.

## **Recurring Lesson Deduction**

- Upcoming recurring lesson is detected.
- Wallet deduction job runs before lesson.
- System checks student wallet balance.
- If sufficient, wallet is debited and booking is confirmed.
- If insufficient, booking remains pending or skipped according to policy.
- Student receives low-balance notification.
- Job result is logged.

## **Scheduler Failure Detection**

- Scheduler heartbeat is expected.
- System detects missing heartbeat.
- Critical scheduled tasks may be delayed.
- System creates operational alert.
- Technical/admin user investigates.
- Scheduler resumes.
- Alert is resolved.

## **Admin Reviews Failed Jobs**

- Admin opens operational dashboard.
- Admin filters failed jobs by module.
- Admin opens failed job details.
- Admin reviews error and related record.
- Admin retries job if safe.
- Retry result is logged.
- Related alert is updated.

## **Report Export Job**

- Admin requests large report export.
- System queues report export job.
- Job processes in background.
- Export file is generated.
- Admin receives notification.
- Export action is audit logged.
- Export expires after configured period.

# **26.40 Exception Handling**

## **Failed Job Retry Not Safe**

The system shall prevent manual retry where retry may cause duplicate financial or booking action unless idempotency is guaranteed.

## **Provider Unavailable**

The system shall retry where appropriate, create alert, and show degraded status if provider failure continues.

## **Scheduler Not Running**

The system shall create critical alert and notify authorized technical/admin users.

## **Queue Backlog Too High**

The system shall create alert when queue backlog exceeds threshold.

## **Missing Business Record**

If related record no longer exists or is archived, the job shall fail safely and log the issue.

## **Duplicate Payment Callback**

The system shall ignore duplicate callback after recording duplicate event.

## **Expired Reminder**

The system shall suppress expired reminder and log suppression.

## **Maintenance Mode Active**

Non-critical jobs may be paused or delayed according to maintenance policy.

# **26.41 Notifications**

Operational notifications may include:

## **Super Admin**

- Scheduler stopped
- Critical queue failure
- Payment provider authentication failure
- Major provider outage
- Maintenance mode activated
- Repeated critical job failures

## **Finance Admin**

- Payment callback failure
- Wallet credit/debit failure
- Payment mismatch
- Instructor earning job failure
- Withdrawal processing failure

## **Operations Admin**

- Meeting creation failure
- Missing meeting link
- Booking confirmation failure
- Recurring lesson generation failure
- No-show job failure

## **CMS Manager**

- Scheduled content publish failed
- Sitemap generation failed
- Media processing failed

## **Technical Admin**

- Queue backlog
- Failed job threshold reached
- Scheduler heartbeat missing
- Provider API failure
- Storage failure

# **26.42 Reports & Analytics**

Operational reports may include:

- Failed jobs by module
- Failed jobs by queue
- Retry success rate
- Queue backlog trend
- Average job processing time
- Critical job failures
- Payment callback failures
- Wallet job failures
- Meeting provider failures
- Notification provider failures
- Scheduler health
- Recurring job failures
- Report export failures
- Provider uptime trend
- Alert count by severity
- Alert resolution time
- Maintenance history

# **26.43 Administrative Configuration**

Administrators with appropriate permission may configure:

- Queue categories
- Retry limits
- Retry intervals
- Failed job alert thresholds
- Queue backlog thresholds
- Scheduler heartbeat threshold
- Provider health check rules
- Alert recipients
- Alert severity rules
- Meeting link missing threshold
- Critical notification failure threshold
- Report export expiry
- Maintenance mode permissions
- Job expiry rules
- Operational dashboard access

# **26.44 Acceptance Criteria**

- Critical background jobs are monitored and failures are visible to authorized users.
- Failed jobs can be reviewed and retried where safe.
- Payment callback processing is idempotent and duplicate callbacks do not duplicate financial effects.
- Wallet credit, debit, refund, referral reward, and recurring deduction jobs are monitored.
- Meeting creation failures and missing meeting links generate operational alerts.
- Scheduled task failures are detectable.
- Critical notifications and provider failures are monitored.
- Operational alerts are routed to appropriate roles or teams.
- Time-sensitive jobs expire or suppress execution when no longer relevant.
- Maintenance mode activation and critical operational actions are audit logged.

# **26.45 Future Enhancements**

This module is designed to support future expansion, including:

- Laravel Horizon dashboard integration
- Advanced queue metrics
- External uptime monitoring
- Incident management
- PagerDuty/Opsgenie integration
- Slack alert integration
- Automated incident creation
- Provider status page integration
- Synthetic transaction checks
- Payment reconciliation automation
- AI anomaly detection
- Auto-healing jobs
- Queue autoscaling
- Advanced scheduler dashboard
- Runbook links from alerts
- Maintenance calendar
- Public status page
- Database replication monitoring
- Backup monitoring
- Disaster recovery dashboard
- Infrastructure cost monitoring
- Multi-region health monitoring

# **26.46 Chapter Summary**

The System Health, Queues, Jobs & Operational Monitoring module defines the reliability backbone of STEM Learning.

It ensures that background jobs, scheduled tasks, queues, provider callbacks, payment processing, wallet operations, booking lifecycle jobs, meeting creation, notification delivery, recurring lessons, report exports, and CMS jobs are visible, traceable, retryable, and auditable.

By requiring idempotency, retry controls, failed job visibility, scheduler monitoring, provider health checks, operational alerts, alert routing, and maintenance readiness, the platform can operate reliably at scale and reduce the risk of silent failures affecting students, instructors, finance, or business operations.

This module completes **PART F - Platform Administration & Operations** by defining the operational monitoring layer needed to keep the platform production-ready.

PART G

# **PART G - NON-FUNCTIONAL REQUIREMENTS**

# **CHAPTER 27 - PERFORMANCE, SCALABILITY & RELIABILITY REQUIREMENTS**

# **27.1 Introduction**

The Performance, Scalability & Reliability Requirements chapter defines the expected quality, speed, stability, resilience, and growth-readiness of the STEM Learning platform.

Unlike functional requirements, which describe what the platform does, non-functional requirements define how well the platform must operate.

STEM Learning is an online learning marketplace where students, instructors, bookings, payments, wallets, meetings, notifications, reports, and admin operations must work reliably. Performance or reliability failure can directly affect revenue, lesson delivery, user trust, instructor satisfaction, and business reputation.

This chapter establishes the baseline expectations for:

- Page speed
- Booking performance
- Payment reliability
- Wallet integrity
- Queue reliability
- Concurrent usage
- Database scalability
- Admin panel responsiveness
- Background job reliability
- Meeting workflow reliability
- System recovery
- Production readiness

# **27.2 Purpose**

The purpose of this chapter is to define measurable and practical non-functional requirements for production operation.

The platform must ensure:

- Users can browse, search, book, pay, and join lessons smoothly.
- Critical workflows remain reliable under load.
- Background jobs do not silently fail.
- Financial workflows remain consistent and idempotent.
- Admin panels remain usable as data grows.
- Reports do not block operational workflows.
- The system can scale gradually as the business grows.
- Recovery mechanisms exist for failures.
- The platform remains stable during peak usage.

# **27.3 Business Objectives**

Performance, scalability, and reliability must support the following business objectives:

- Provide a fast student booking experience.
- Reduce payment and booking failures.
- Protect financial transaction accuracy.
- Support increasing students, instructors, bookings, and content.
- Maintain instructor trust through reliable lesson and earning workflows.
- Prevent missed lessons caused by system delays.
- Support global timezone-based usage.
- Keep admin operations responsive.
- Support future marketplace and international growth.
- Reduce operational firefighting.

# **27.4 Scope**

This chapter covers:

## **Performance**

- Public website performance
- Marketplace search performance
- Instructor profile performance
- Booking flow performance
- Admin panel performance
- Dashboard performance
- API/backend response expectations
- Report generation performance

## **Scalability**

- User growth
- Instructor growth
- Booking growth
- Wallet transaction growth
- Notification growth
- CMS/content growth
- Database growth
- Queue growth

## **Reliability**

- Booking reliability
- Payment reliability
- Wallet reliability
- Meeting creation reliability
- Notification reliability
- Queue reliability
- Scheduled job reliability
- Recovery and retry rules

# **27.5 Performance Philosophy**

The platform should follow this principle:

Critical user journeys must remain fast, predictable, and reliable even as platform data grows.

Critical journeys include:

- Student registration
- Instructor discovery
- Instructor profile viewing
- Slot selection
- Demo booking
- Paid booking
- Wallet recharge
- Payment confirmation
- Meeting access
- Lesson reminders
- Admin booking review
- Financial reporting

# **27.6 Critical User Journeys**

Performance expectations should prioritize business-critical journeys.

## **Student Journey**

- Browse subjects
- Search instructors
- View instructor profile
- Check availability
- Book demo
- Pay for paid lesson
- Recharge wallet
- Join lesson
- Submit review

## **Instructor Journey**

- Manage profile
- Set availability
- View upcoming lessons
- Join lesson
- Assign homework
- View earnings
- Request withdrawal

## **Admin Journey**

- Monitor dashboard
- Manage bookings
- Review payments
- Handle support cases
- Approve instructor
- Review wallet transactions
- Export reports
- Manage settings

# **27.7 Public Website Performance**

Public pages should load quickly because they affect SEO, user trust, and conversion.

Applicable pages:

- Homepage
- Landing pages
- Subject pages
- Blog posts
- FAQ sections
- Legal pages
- Instructor public profiles

Requirements:

- Public pages should be cacheable where possible.
- Images should be optimized.
- SEO metadata should render reliably.
- CMS blocks should not create heavy page loads.
- Public content should avoid unnecessary authenticated requests.
- Sitemap and robots should not slow public page rendering.

# **27.8 Marketplace Search Performance**

Marketplace search is a conversion-critical feature.

Search should support:

- Subject filters
- Education level filters
- Language filters
- Rating filters
- Availability filters
- Price filters, where enabled
- Country and timezone filters
- Sorting

Performance expectations:

- Search results should return quickly for common queries.
- Filters should be indexed or optimized.
- Search should paginate results.
- Expensive availability calculations should be cached or precomputed where needed.
- Zero-result handling should be fast and helpful.

# **27.9 Instructor Profile Performance**

Instructor profiles must load efficiently because they are key conversion pages.

Instructor profile data may include:

- Basic profile
- Subjects
- Education levels
- Languages
- Experience
- Education
- Certificates
- Reviews
- Ratings
- Availability summary
- Demo booking CTA
- SEO metadata

Performance rules:

- Public profile should avoid loading unnecessary private data.
- Reviews should be paginated.
- Availability summary should be optimized.
- Heavy calendar data should load separately if needed.
- Profile media should be optimized.

# **27.10 Availability and Slot Generation Performance**

Availability and slot generation can become expensive.

The platform must optimize:

- Instructor weekly availability
- Blocked dates
- Vacation mode
- Existing bookings
- Buffer time
- Duration-based slots
- Student timezone conversion
- Recurring booking validation

Rules:

- Slot generation should be efficient for the visible date range.
- The system should not generate unlimited future slots unnecessarily.
- Common slot queries may be cached.
- Confirmed booking conflict checks must be authoritative and database-safe.
- Booking confirmation must revalidate availability before final confirmation.

# **27.11 Booking Flow Performance**

Booking flow must feel smooth and reliable.

Performance expectations:

- Slot selection should respond quickly.
- Temporary reservation should be created reliably.
- Booking summary should load without delay.
- Payment handoff should not timeout.
- Booking confirmation should complete safely after payment.
- Duplicate booking attempts must be prevented.
- Booking status should be clearly updated after payment.

Booking is a critical workflow and must be prioritized over low-priority background jobs.

# **27.12 Payment and Wallet Performance**

Payment and wallet workflows are financially sensitive.

Requirements:

- Payment initiation should be fast.
- Razorpay callback processing should be reliable.
- Wallet credit after successful payment should be timely.
- Wallet debit for booking should be atomic.
- Refund credits should be traceable.
- Wallet balance should be derived consistently.
- Duplicate payment callbacks must not create duplicate credits.
- Wallet transaction creation should be protected with database transactions.

# **27.13 Meeting Workflow Reliability**

Meeting creation and access must be reliable.

Requirements:

- Meeting links should be created after booking confirmation.
- Meeting creation failure must be retried.
- Missing meeting link before lesson must alert operations.
- Meeting link display should be fast.
- Meeting access should respect user authorization.
- Meeting cancellation/reschedule should update meeting details where supported.
- Attendance and no-show data should be captured where provider supports it.

# **27.14 Notification Performance**

Notifications should not block user workflows.

Rules:

- Email, SMS, WhatsApp, and in-app notifications should be queued.
- Critical notifications should have retry rules.
- Expired reminders should be suppressed.
- Duplicate notifications should be prevented.
- Notification provider failures should be logged and alerted.
- Lesson reminder jobs should be prioritized.

Examples of critical notifications:

- Booking confirmation
- Payment success/failure
- Wallet debit/credit
- Lesson reminder
- Meeting link
- Cancellation/reschedule
- Low wallet balance before recurring lesson

# **27.15 Admin Panel Performance**

The admin panel must remain responsive as data grows.

Requirements:

- Tables should be paginated.
- Filters should use indexed columns.
- Search should be optimized.
- Heavy statistics should be cached or loaded separately.
- Reports should not block normal admin operations.
- Large exports should run asynchronously.
- Admin dashboard widgets should avoid expensive real-time queries where possible.

Admin modules affected:

- Students
- Instructors
- Bookings
- Payments
- Wallet transactions
- Withdrawals
- Reviews
- Support cases
- Reports
- Audit logs

# **27.16 Reporting Performance**

Reports can be expensive and should not slow operational workflows.

Rules:

- Large reports should be queued.
- CSV exports should be generated asynchronously when large.
- Reports should support date-range limits.
- Sensitive exports should be audit logged.
- Repeated KPI reports may use snapshots.
- Dashboard charts may use cached aggregates.
- Report filters should be optimized.

# **27.17 Database Performance**

The database must support growing business data.

Important high-volume tables may include:

- Users
- Student profiles
- Instructor profiles
- Availability records
- Bookings
- Booking occurrences
- Wallet transactions
- Payments
- Notifications
- Activity logs
- Audit logs
- Support cases
- Reviews
- Messages
- Reports/exports

Database requirements:

- Use proper indexes for frequent filters.
- Avoid unbounded queries.
- Use pagination for large datasets.
- Use transactions for critical financial and booking workflows.
- Use unique constraints for idempotency.
- Archive or partition very large logs in future.
- Store snapshots where historical accuracy matters.

# **27.18 Caching Strategy**

Caching should improve performance without compromising correctness.

Good candidates for caching:

- Public CMS pages
- Global settings
- SEO metadata
- Navigation
- Public subject lists
- FAQ lists
- Country/currency masters
- Instructor profile summary
- Marketplace filters
- Dashboard aggregates
- KPI snapshots

Do not blindly cache:

- Wallet balance unless carefully invalidated
- Payment status
- Booking confirmation state
- Availability conflict checks
- Sensitive admin permissions without safe invalidation

# **27.19 Queue Scalability**

The queue system must handle increasing background workload.

Queue workloads include:

- Notifications
- Payment follow-ups
- Wallet jobs
- Meeting jobs
- Booking jobs
- Recurring lessons
- Reports
- CMS jobs
- Media processing
- Analytics snapshots

Requirements:

- Critical queues should be separated from low-priority queues.
- Failed jobs should be monitored.
- Queue backlog should alert admins/technical users.
- Jobs should be idempotent where needed.
- Long-running jobs should not block critical jobs.
- Queue workers should be scalable.

# **27.20 Scheduled Job Reliability**

Scheduled jobs must run reliably.

Critical scheduled jobs include:

- Lesson reminders
- Recurring wallet deductions
- Recurring booking generation
- Booking auto-completion
- No-show evaluation
- Instructor earning eligibility
- Notification retries
- Report snapshots
- Sitemap generation
- Cleanup tasks

Requirements:

- Scheduler health should be monitored.
- Missed scheduled tasks should be detected.
- Overlapping critical jobs should be prevented where necessary.
- Time-sensitive jobs should check current state before execution.
- Scheduled job failures should be logged and alerted.

# **27.21 Scalability Stages**

The platform should scale in stages.

## **Stage 1 - Startup Launch**

Expected characteristics:

- Limited students
- Limited instructors
- Basic reports
- INR/Razorpay only
- Single-region infrastructure
- Manual operations supported

## **Stage 2 - Growing Marketplace**

Expected characteristics:

- More instructors
- More bookings
- More wallet transactions
- Increased notifications
- More public SEO traffic
- Stronger queue monitoring
- More report exports

## **Stage 3 - Regional Expansion**

Expected characteristics:

- Multiple countries
- More timezone complexity
- More subject pages
- Multi-currency readiness
- More payment integrations
- Regional dashboards
- Stronger analytics

## **Stage 4 - Large Marketplace**

Expected characteristics:

- High booking volume
- Large audit logs
- Advanced BI
- Dedicated search engine
- Data warehouse
- Autoscaling
- Incident management

# **27.22 Reliability Requirements**

Reliability means the platform performs correctly and consistently.

Critical reliability areas:

- Booking lifecycle
- Payment confirmation
- Wallet ledger
- Meeting creation
- Notification delivery
- Recurring deductions
- Instructor earnings
- Refund credits
- Admin actions
- Settings changes

Reliability requires:

- Database transactions
- Idempotency
- Retry policies
- Audit logs
- State validation
- Monitoring
- Alerts
- Recovery procedures

# **27.23 Availability Expectations**

The platform should target high availability for user-facing workflows.

Priority areas:

- Public website
- Marketplace search
- Booking flow
- Student dashboard
- Instructor dashboard
- Meeting access
- Payment flow
- Admin operations

Version 1 may not require enterprise multi-region availability, but production deployment should avoid single points of preventable failure where reasonable.

# **27.24 Graceful Degradation**

When non-critical services fail, the platform should degrade gracefully.

Examples:

- If WhatsApp fails, email/in-app notification may still send.
- If sitemap generation fails, booking should continue.
- If report export fails, core user workflows should continue.
- If analytics tracking fails, checkout should not fail.
- If blog content is unavailable, admin workflows should continue.

Critical workflows should not depend on non-critical systems.

# **27.25 Error Handling**

Errors must be safe and user-friendly.

Rules:

- Do not expose stack traces to users.
- Show clear user-facing messages.
- Log technical details for admins/developers.
- Preserve user input where possible.
- Prevent duplicate actions on retry.
- Give next steps for payment or booking uncertainty.
- Use generic messages for security-sensitive failures.

# **27.26 Recovery Requirements**

The platform should support recovery from failure.

Examples:

- Retry failed notifications.
- Retry meeting creation.
- Reconcile payment callbacks.
- Retry wallet credit safely.
- Re-run report exports.
- Reprocess failed recurring booking jobs.
- Restore scheduled job processing.
- Resolve queue backlog.
- Recover from provider downtime.

Recovery must not duplicate money movement or bookings.

# **27.27 Data Consistency Requirements**

Data consistency is critical.

Requirements:

- Wallet ledger must remain consistent.
- Payment status must match gateway verification.
- Booking status must match payment and reservation state.
- Instructor earnings must match completed lesson records.
- Refund credits must link to source booking/payment.
- Referral rewards must link to eligible lesson.
- Audit logs must preserve sensitive action history.

Financial and booking data should prefer correctness over speed.

# **27.28 Concurrency Requirements**

The platform must handle concurrent user actions safely.

Examples:

- Two students attempt to book same instructor slot.
- Student clicks payment confirmation multiple times.
- Razorpay sends duplicate callbacks.
- Admin and system job update same booking.
- Recurring job and cancellation action overlap.
- Instructor changes availability while booking is in progress.

Concurrency protections may include:

- Database transactions
- Row-level locks where needed
- Unique constraints
- Idempotency keys
- State-machine validation
- Conflict checks

# **27.29 Security and Performance Balance**

Performance optimizations must not weaken security.

Rules:

- Do not cache sensitive data unsafely.
- Do not expose unauthorized records for speed.
- Permission checks must remain enforced.
- Admin reports must respect access control.
- Public pages must not expose private profile data.
- Payment and wallet flows must verify all conditions.
- File and media access must follow visibility policy.

# **27.30 File and Media Performance**

Media can affect public website and profile performance.

Requirements:

- Images should be optimized.
- Public profile photos should use appropriate sizes.
- Large files should not block page load.
- Homework/resource uploads should be validated.
- Async upload processing should be supported where needed.
- Media storage should be scalable.
- Broken media should fail gracefully.

# **27.31 API and Backend Response Expectations**

Internal backend actions should be designed for predictable response times.

General expectations:

- Simple page loads should be fast.
- Search/filter pages should be optimized and paginated.
- Long-running tasks should be queued.
- Payment and booking actions should complete reliably.
- Heavy calculations should use background processing or caching.
- Admin exports should not timeout.

The exact technical targets may be finalized in the technical architecture document.

# **27.32 Performance Targets**

Recommended initial targets for Version 1:

## **Public Pages**

- Common public pages should load within acceptable web performance expectations.
- Cached CMS pages should be fast.
- Images should be optimized for web.

## **Marketplace Search**

- Common searches should return quickly with pagination.
- Heavy availability calculations should be optimized.

## **Booking Flow**

- Slot reservation and booking summary should respond quickly.
- Payment confirmation should be reliable and state-safe.

## **Admin Tables**

- Paginated admin tables should load without timeout.
- Filters should remain usable as records grow.

## **Reports**

- Small reports may load directly.
- Large reports should be queued.

Final numeric targets should be defined during technical architecture and load testing.

# **27.33 Load Testing Readiness**

The platform should be designed for load testing.

Test scenarios may include:

- Public homepage traffic
- Instructor search traffic
- Instructor profile traffic
- Slot availability lookup
- Demo booking
- Paid booking and payment callback
- Wallet recharge
- Recurring deduction
- Lesson reminders
- Admin dashboard
- Report export
- Notification burst

Load testing helps identify bottlenecks before scale.

# **27.34 Scalability Metrics**

The platform should monitor scalability metrics.

Examples:

- Requests per minute
- Average response time
- Slow query count
- Queue length
- Queue processing time
- Failed job count
- Payment callback latency
- Wallet transaction latency
- Meeting creation latency
- Notification delivery latency
- Database size
- Cache hit rate
- Report generation time
- Error rate

# **27.35 Reliability Metrics**

Reliability metrics may include:

- Booking success rate
- Payment success processing rate
- Wallet credit success rate
- Meeting creation success rate
- Notification delivery success rate
- Recurring deduction success rate
- Failed job count
- Job retry success rate
- Scheduler uptime
- Provider failure rate
- Incident count
- Mean time to recovery, future

# **27.36 Functional Non-Functional Requirements**

## **Performance**

### **Public Page Performance**

**Priority:** High

Public CMS, landing, legal, blog, subject, and instructor profile pages shall be optimized for fast loading.

### **Marketplace Search Performance**

**Priority:** Critical

Marketplace search and filtering shall be optimized and paginated.

### **Instructor Profile Performance**

**Priority:** High

Instructor public profiles shall load efficiently, with heavy data such as reviews or availability paginated or loaded separately where necessary.

### **Booking Flow Performance**

**Priority:** Critical

Booking slot selection, reservation, summary, and confirmation workflows shall respond reliably and without unnecessary delay.

### **Admin Table Performance**

**Priority:** High

Admin tables shall use pagination, filters, and optimized queries to prevent timeout as data grows.

### **Report Performance**

**Priority:** High

Large reports and exports shall be processed asynchronously where required.

## **Scalability**

### **User Growth Scalability**

**Priority:** High

The platform shall support growth in students, instructors, and administrators without requiring major redesign.

### **Booking Growth Scalability**

**Priority:** Critical

The booking system shall support increasing booking volume while preventing slot conflicts and duplicate confirmations.

### **Wallet Transaction Scalability**

**Priority:** Critical

Wallet ledger and transaction processing shall support increasing transaction volume while preserving consistency.

### **Notification Scalability**

**Priority:** High

Notification processing shall scale through queued delivery and provider monitoring.

### **Report Scalability**

**Priority:** Medium

Reporting shall support growing data through pagination, filtering, snapshots, caching, and queued exports.

### **CMS Scalability**

**Priority:** Medium

CMS pages, blogs, FAQs, media, redirects, and sitemap generation shall scale as public content grows.

## **Reliability**

### **Payment Reliability**

**Priority:** Critical

Payment processing shall be idempotent, verified, logged, and protected from duplicate callbacks.

### **Wallet Reliability**

**Priority:** Critical

Wallet credits, debits, refunds, and rewards shall be atomic, traceable, and ledger-based.

### **Booking Reliability**

**Priority:** Critical

Booking confirmation shall revalidate slot availability and prevent duplicate booking of the same slot.

### **Meeting Reliability**

**Priority:** Critical

Meeting creation shall be retried on failure and missing meeting links shall generate alerts.

### **Notification Reliability**

**Priority:** High

Critical notifications shall be queued, retried, logged, and monitored.

### **Queue Reliability**

**Priority:** Critical

Critical queues shall be monitored, and failed jobs shall be visible and retryable where safe.

### **Scheduler Reliability**

**Priority:** Critical

Scheduled tasks shall be monitored for missed or delayed execution.

### **Data Consistency**

**Priority:** Critical

Financial, booking, meeting, and earning workflows shall prioritize data consistency over speed.

### **Idempotency**

**Priority:** Critical

Critical workflows shall support idempotency to prevent duplicate effects.

### **Graceful Degradation**

**Priority:** High

Failure of non-critical systems shall not break critical platform workflows.

## **Monitoring**

### **Operational Monitoring**

**Priority:** High

The platform shall expose or integrate operational monitoring for queues, jobs, scheduled tasks, providers, and critical workflows.

### **Alerting**

**Priority:** High

Critical operational failures shall generate alerts for the appropriate team or role.

### **Performance Metrics**

**Priority:** Medium

The platform shall track key performance metrics such as response time, queue delay, failed jobs, and provider failures.

### **NFR-MON-004 - Auditability**

**Priority:** Critical

Sensitive reliability and recovery actions shall be audit logged.

# **27.37 Business Rules**

- Critical financial workflows must prioritize correctness over speed.
- Payment callbacks must be idempotent.
- Wallet transactions must never be duplicated due to retries.
- Booking confirmation must prevent double booking.
- Critical background jobs must be monitored.
- Large reports should not block user-facing or admin workflows.
- Low-priority jobs must not delay payment, booking, wallet, meeting, or lesson reminder jobs.
- Cached data must not bypass authorization checks.
- Historical financial and booking data must remain consistent after settings changes.
- Non-critical service failure should degrade gracefully where possible.
- Performance optimizations must not expose private data.
- System errors must not expose technical stack traces to public users.

# **27.38 Validation Rules**

Critical jobs must define retry behavior.

Critical financial jobs must define idempotency key.

Booking conflict checks must validate current state before confirmation.

Large report exports must respect configured limits.

Queue retry limits must be configured.

Expired jobs must not perform user-impacting actions.

Cached public content must respect publication status.

Cached user-specific content must respect permissions.

Sensitive monitoring data must be visible only to authorized users.

# **27.39 Reliability Workflows**

## **Safe Payment Callback Processing**

- Razorpay sends payment callback.
- System verifies callback signature.
- System checks idempotency key.
- System verifies related payment attempt.
- System updates payment status.
- System credits wallet or confirms booking once.
- Duplicate callback is ignored safely.
- Audit log is created.

## **Concurrent Slot Booking Protection**

- Two students attempt to book same slot.
- System creates or checks reservation.
- Booking confirmation revalidates slot.
- Database constraint or lock prevents duplicate confirmation.
- One booking succeeds.
- Other user receives unavailable slot message.
- Conflict is logged where needed.

## **Failed Meeting Creation Recovery**

- Booking is confirmed.
- Meeting creation job starts.
- Provider API fails.
- System retries according to policy.
- Failure persists.
- Operations alert is created.
- Admin or system resolves meeting link.
- Student and instructor are notified.

## **Large Report Export**

- Admin requests large report.
- System validates permission and filters.
- Export job is queued.
- Admin receives pending message.
- Report file is generated asynchronously.
- Admin is notified when ready.
- Export action is audit logged.

## **Queue Backlog Alert**

- Queue backlog exceeds threshold.
- System detects delay.
- Alert is generated.
- Technical/admin user reviews queue.
- Workers are scaled or failed jobs are handled.
- Alert is resolved.

# **27.40 Exception Handling**

## **Slow Search Query**

The system shall use pagination, indexing, or safe fallback rather than timing out.

## **Payment Uncertain State**

If payment status is uncertain, the system shall show pending status, verify with gateway, and avoid duplicate wallet or booking effects.

## **Wallet Transaction Failure**

The system shall roll back incomplete transaction and alert finance/admin where required.

## **Booking Conflict**

The system shall prevent duplicate booking and show user-friendly slot unavailable message.

## **Queue Failure**

Critical queue failure shall create alert and preserve failed job details.

## **Report Timeout**

Large report should be moved to queued export instead of failing user request.

## **Provider Downtime**

Provider downtime shall trigger degraded status and recovery workflow where applicable.

# **27.41 Reports & Monitoring**

Performance and reliability reports may include:

- Page response time
- Marketplace search latency
- Booking flow completion time
- Payment callback processing time
- Wallet transaction processing time
- Meeting creation success rate
- Queue backlog
- Failed job count
- Scheduled job success rate
- Report generation time
- Error rate
- Provider failure rate
- Slow query report
- Cache hit rate
- Notification delivery delay
- Booking success rate
- Payment processing success rate

# **27.42 Administrative Configuration**

Administrators or technical operators may configure:

- Queue priority
- Retry limits
- Retry intervals
- Report export limits
- Cache duration
- Scheduler thresholds
- Alert recipients
- Failed job thresholds
- Provider timeout thresholds
- Booking reservation expiry
- Meeting creation retry policy
- Notification retry policy
- Maintenance mode behavior
- Monitoring dashboard access

# **27.43 Acceptance Criteria**

- Critical public, marketplace, booking, payment, wallet, meeting, and admin workflows perform reliably under expected Version 1 load.
- Marketplace search and admin tables use pagination and optimized queries.
- Booking confirmation prevents duplicate slot booking under concurrent attempts.
- Payment callback processing is idempotent and duplicate callbacks do not duplicate wallet or booking effects.
- Wallet transactions are ledger-based, atomic, and traceable.
- Large exports and heavy reports can be processed asynchronously.
- Critical background jobs are monitored and failures generate alerts.
- Meeting creation failures are retried and missing meeting links are escalated.
- Scheduled tasks are monitored for missed or delayed execution.
- Non-critical service failures degrade gracefully without breaking critical workflows.

# **27.44 Future Enhancements**

This chapter is designed to support future expansion, including:

- Dedicated search engine
- Data warehouse
- Advanced caching strategy
- CDN optimization
- Global edge caching
- Multi-region deployment
- Read replicas
- Queue autoscaling
- Background job autoscaling
- Advanced observability
- Distributed tracing
- Application performance monitoring
- Synthetic monitoring
- Incident response automation
- Public status page
- Disaster recovery automation
- Automated load testing
- AI-based anomaly detection
- Predictive scaling
- Database partitioning
- Long-term audit archive
- Advanced BI infrastructure

# **27.45 Chapter Summary**

The Performance, Scalability & Reliability Requirements chapter defines the operational quality expectations for STEM Learning.

It ensures that the platform remains fast, stable, scalable, and reliable across public pages, marketplace search, instructor profiles, booking, payments, wallet, meetings, notifications, admin panels, reports, queues, and scheduled jobs.

By prioritizing critical user journeys, enforcing idempotency, protecting data consistency, supporting queue scalability, monitoring scheduled jobs, optimizing reports, and requiring graceful degradation, STEM Learning becomes ready for a reliable Version 1 launch and future growth into a larger global online learning marketplace.

#

#

#

# **FINAL SUMMARY & IMPLEMENTATION READINESS CHECKLIST**

# **1\. Book 2 Purpose**

Book 2 defines the functional and operational requirements for the STEM Learning platform.

It converts the business strategy from Book 1 into structured functional modules covering:

- User lifecycle
- Student management
- Instructor management
- Academic framework
- Learning plans
- Marketplace discovery
- Booking
- Scheduling
- Meetings
- Wallet
- Payments
- Instructor earnings
- Referrals
- Notifications
- Reviews
- Admin operations
- CMS
- Reports
- Settings
- Audit logs
- Support cases
- System health
- Performance and reliability

Book 2 is now strong enough to guide:

- Database design
- Laravel domain architecture
- Filament admin resources
- Livewire frontend screens
- Service-layer planning
- Queue/job planning
- Payment and wallet implementation
- Booking engine implementation
- QA test case preparation
- Future technical architecture documentation

# **2\. Completed Book 2 Chapter List**

## **PART A - Identity & User Lifecycle**

### **Chapter 1 - Authentication & Authorization**

Covers registration, login, verification, password reset, session rules, account restrictions, and access protection.

### **Chapter 2 - Student Management**

Covers student profile, preferences, goals, favorites, wallet relation, referral code, dashboard, and student lifecycle.

### **Chapter 3 - Instructor Management & Professional Lifecycle**

Covers instructor application, profile, KYC, approval, expertise, availability readiness, public profile, and lifecycle.

## **PART B - Academic Foundation**

### **Chapter 4 - Academic Framework & Curriculum Management**

Covers academic categories, subjects, education levels, skill levels, curriculum, modules, topics, subtopics, and outcomes.

### **Chapter 5 - Curriculum, Learning Roadmaps & Competency Management**

Covers curriculum structure, learning roadmaps, competencies, milestones, prerequisites, and progress mapping.

### **Chapter 6 - Student Learning Plans & Academic Progress Management**

Covers student learning goals, learning plans, instructor assignment, progress reviews, milestones, and plan adjustment.

### **Chapter 7 - Learning Activities, Homework & Educational Resources**

Covers homework, resources, notes, PDFs, submissions, feedback, activity lifecycle, and future extensibility.

## **PART C - Marketplace & Discovery**

### **Chapter 8 - Discovery, Search & Recommendation Engine**

Covers marketplace search, filters, sorting, recommendations, favorites, recently viewed, analytics, and discovery conversion.

### **Chapter 9 - Instructor Marketplace, Public Profiles & Trust System**

Covers instructor public profiles, trust indicators, ratings, reviews, SEO, pricing display, and marketplace CTAs.

### **Chapter 10 - Availability & Scheduling Engine**

Covers instructor availability, blocked dates, buffers, vacation mode, timezone handling, waitlist, recurring availability, and slot generation.

### **Chapter 11 - Booking & Reservation Engine**

Covers demo booking, paid booking, recurring booking, slot reservation, payment linkage, cancellation, reschedule, no-show, completion, and settlement trigger.

### **Chapter 12 - Virtual Classroom & Meeting Management**

Covers platform-owned meeting links, provider strategy, Google Meet/Zoom readiness, recording, attendance, admin observer, and technical issue handling.

## **PART D - Financial**

### **Chapter 13 - Wallet Management & Ledger System**

Covers student wallet, wallet ledger, recharge, debit, refund, recurring deductions, referral credits, promotional credits, and wallet statements.

### **Chapter 14 - Payment Gateway, Checkout & Invoice Management**

Covers Razorpay-first Version 1 payment model, INR settlement, checkout, verification, invoices, payment failure, and reconciliation.

### **Chapter 15 - Instructor Earnings, Incentives, Settlement & Withdrawals**

Covers instructor pay, earnings, demo incentives, settlement delay, withdrawable balance, withdrawal request, approval, rejection, and payout status.

### **Chapter 16 - Student Referral, Rewards & Promotional Credit System**

Covers student-only referrals, per-class reward, max reward classes, wallet credits, promotional credits, campaigns, and fraud controls.

## **PART E - Engagement**

### **Chapter 17 - Notifications, Communication & Messaging System**

Covers email, SMS, WhatsApp, in-app notifications, templates, delivery logs, reminders, controlled student-instructor messaging, and leakage prevention.

### **Chapter 18 - Reviews, Ratings, Feedback & Quality Assurance**

Covers verified lesson reviews, rating dimensions, private feedback, moderation, public display, quality alerts, and instructor quality analytics.

## **PART F - Platform Administration & Operations**

### **Chapter 19 - Reports, Analytics & Business Intelligence**

Covers dashboards, student analytics, instructor analytics, booking analytics, wallet reports, payment reports, revenue reports, referral reports, review reports, and exports.

### **Chapter 20 - Global Settings, Configuration & Feature Control**

Covers global settings, module settings, feature flags, country overrides, sensitive settings, validation, dependencies, and configuration governance.

### **Chapter 21 - Country, Currency, Localization & Regional Operations**

Covers India-first INR strategy, Razorpay operations, country master, timezone handling, localization readiness, regional features, and future multi-currency expansion.

### **Chapter 22 - CMS, SEO, Public Pages & Content Management**

Covers pages, blocks, FAQs, blogs, legal pages, SEO metadata, sitemap, robots, redirects, public subject pages, and instructor profile SEO.

### **Chapter 23 - Admin User Management, Roles, Permissions & Access Control**

Covers internal admin users, roles, permission groups, financial restrictions, sensitive actions, admin lifecycle, support agents, and access review.

### **Chapter 24 - Activity Logs, Audit Trail & Compliance Monitoring**

Covers activity logs, audit trail, financial audit, booking audit, settings audit, admin action logs, compliance monitoring, suspicious activity, and immutable evidence.

### **Chapter 25 - Support, Disputes & Operational Case Management**

Covers support tickets, booking disputes, no-show disputes, refund disputes, payment issues, wallet issues, technical issues, escalation, and case lifecycle.

### **Chapter 26 - System Health, Queues, Jobs & Operational Monitoring**

Covers queue monitoring, failed jobs, scheduled tasks, Razorpay callbacks, meeting failures, recurring jobs, wallet jobs, provider monitoring, and operational alerts.

## **PART G - Non-Functional Requirements**

### **Chapter 27 - Performance, Scalability & Reliability Requirements**

Covers platform speed, scalability, reliability, concurrency, idempotency, caching, queue scaling, database performance, reporting performance, graceful degradation, and operational resilience.

# **3\. Core Version 1 Business Decisions**

The following decisions are finalized for Version 1.

## **Platform Model**

- Online-only learning platform.
- One-to-one learning marketplace.
- Students discover and book instructors.
- Instructors manage profile and availability.
- Admin controls pricing, rules, approvals, payments, and operations.

## **Terminology**

- Use **Instructor**, not tutor or teacher.
- Use **Student** for learner account.
- Use **Admin** for internal platform operator.

## **Payment Strategy**

- Razorpay only in Version 1.
- INR-first settlement and reporting.
- Wallet recharge through Razorpay.
- Refunds credited to wallet.
- Multi-currency is future-ready but not required for Version 1.

## **Wallet Strategy**

- Student wallet is ledger-based.
- Wallet supports recharge, booking debit, refunds, referral credits, promotional credits, and recurring deductions.
- Wallet balance must be traceable and auditable.

## **Instructor Compensation**

- Student price and instructor pay are separate.
- Instructor does not see student-facing price or platform margin.
- Admin controls instructor pay rules.
- Instructor earnings are created after lesson completion rules.
- Withdrawals require admin/finance workflow.

## **Booking Strategy**

- Free demo booking supported.
- Paid one-to-one booking supported.
- Recurring daily/weekly booking supported.
- Slot reservation required.
- Payment confirmation required for paid booking.
- Meeting link generated after booking confirmation.
- No-show, cancellation, reschedule, and technical issue policies are configurable.

## **Meeting Strategy**

- Platform-owned meeting links.
- Google Meet or Zoom compatible.
- Provider-agnostic design.
- Admin observer supported.
- Recording support future-ready.
- Personal contact leakage should be minimized.

## **Referral Strategy**

- Student-only referral in Version 1.
- Wallet credit only.
- Reward after paid class completion.
- Per-class reward supported.
- Maximum 10 rewarded classes recommended.
- Suggested reward: 5% per eligible class, configurable.

## **Communication Strategy**

- Email, SMS, WhatsApp, and in-app notifications.
- Critical notifications cannot depend on marketing consent.
- Student-instructor messaging only after confirmed paid booking or active learning relationship.
- Off-platform communication should be restricted by policy and review tools.

## **CMS Strategy**

- CMS supports pages, legal pages, FAQs, blogs, SEO, sitemap, robots, redirects, and content blocks.
- FAQs can be categorized and displayed as accordions.
- No need for standalone SEO FAQ pages in Version 1 unless later required.

# **4\. Recommended Laravel Implementation Architecture**

The platform should be implemented as a domain-driven Laravel monolith.

Recommended architecture:

app/

Auth/

Student/

Instructor/

Academic/

Learning/

Marketplace/

Availability/

Booking/

Meeting/

Wallet/

Payment/

Earnings/

Referral/

Notification/

Review/

CMS/

Reporting/

Settings/

Support/

Audit/

Operations/

Each domain should include where needed:

Actions/

Contracts/

DTOs/

Enums/

Events/

Exceptions/

Jobs/

Listeners/

Models/

Policies/

Repositories/

Services/

Validation/

Recommended implementation principles:

- Controllers and Filament resources should stay thin.
- Business rules should live in services/actions.
- Financial workflows should use database transactions.
- Critical workflows should use idempotency keys.
- Admin actions should use policies/permissions.
- Background workflows should use queues/jobs.
- Sensitive changes should be audit logged.
- Public frontend can use Livewire.
- Admin panel can use Filament.

# **5\. Suggested Implementation Phase Plan**

## **Phase 1 - Foundation**

Build:

- Authentication
- User base
- Roles and permissions
- Admin panel access
- Global settings
- Country/currency master
- Activity logs
- CMS foundation

Output:

- Secure admin foundation
- Public website foundation
- Configurable settings foundation

## **Phase 2 - Student & Instructor Lifecycle**

Build:

- Student profiles
- Instructor applications
- Instructor profiles
- KYC/documents
- Instructor approval workflow
- Instructor public profile
- Instructor dashboard basics

Output:

- Marketplace user foundation ready.

## **Phase 3 - Academic Foundation**

Build:

- Academic categories
- Subjects
- Education levels
- Skill levels
- Curriculum
- Modules/topics/outcomes
- Learning goals
- Learning plans
- Homework/resource basics

Output:

- Educational structure ready for booking and learning.

## **Phase 4 - Marketplace & Discovery**

Build:

- Instructor listing
- Search and filters
- Instructor profile page
- Favorites
- Recently viewed
- Waitlist, if needed
- Public subject pages

Output:

- Student can discover instructors.

## **Phase 5 - Availability & Booking**

Build:

- Instructor availability
- Slot generation
- Blocked dates
- Vacation mode
- Demo booking
- Paid booking
- Recurring booking
- Reservation expiry
- Cancellation/reschedule/no-show rules

Output:

- Core booking engine ready.

## **Phase 6 - Payment & Wallet**

Build:

- Wallet ledger
- Razorpay payment
- Wallet recharge
- Wallet debit
- Payment verification
- Refund to wallet
- Invoice/receipt
- Payment reports

Output:

- Financial foundation ready.

## **Phase 7 - Meeting & Lesson Execution**

Build:

- Meeting provider abstraction
- Meeting link generation
- Lesson reminders
- Join access rules
- Attendance tracking readiness
- Technical issue reporting
- Lesson completion workflow

Output:

- Online lesson delivery ready.

## **Phase 8 - Instructor Earnings & Withdrawals**

Build:

- Instructor pay rules
- Earning creation
- Holds
- Settlement delay
- Withdrawal request
- Approval/rejection
- Payout marking
- Finance reports

Output:

- Instructor financial workflow ready.

## **Phase 9 - Engagement**

Build:

- Notifications
- Templates
- Delivery logs
- Controlled messaging
- Reviews
- Ratings
- Private feedback
- Referral rewards
- Promotional credits

Output:

- Retention and trust systems ready.

## **Phase 10 - Operations & Admin Scale**

Build:

- Reports
- BI dashboards
- Support cases
- Disputes
- Audit dashboard
- Queue/job monitoring
- Failed job review
- Operational alerts

Output:

- Production operations ready.

# **6\. Implementation Readiness Checklist**

## **Business Readiness**

- Business name finalized.
- Logo and brand identity finalized.
- Domain purchased.
- Support email finalized.
- Legal pages drafted.
- Refund policy finalized.
- Instructor agreement drafted.
- Student terms finalized.
- Pricing model finalized.
- Demo lesson policy finalized.
- Cancellation/reschedule policy finalized.
- No-show policy finalized.
- Referral campaign rule finalized.
- Instructor payout rule finalized.

## **Technical Readiness**

- Laravel project initialized.
- Filament admin installed.
- Spatie Permission installed.
- Filament Shield installed/configured.
- MySQL configured.
- Redis configured.
- Queue workers configured.
- Scheduler configured.
- Storage configured.
- Email provider configured.
- Razorpay configured.
- Activity log package configured.
- Media library configured.
- Settings package configured.
- Backup strategy planned.
- Deployment pipeline planned.

## **Admin Foundation Checklist**

- Super Admin role.
- Admin login.
- Admin user management.
- Role/permission groups.
- Admin panel access control.
- Sensitive action permissions.
- Financial permissions.
- Report export permissions.
- Audit log access permissions.
- Settings access permissions.

## **CMS Checklist**

- Pages.
- Page blocks.
- Legal pages.
- FAQs with audience/category.
- Blogs/articles.
- Categories/tags.
- Media uploads.
- SEO metadata.
- Sitemap.
- Robots.txt.
- Redirects.
- Public subject pages.
- Instructor profile SEO.

## **Student Checklist**

- Student registration.
- Email verification.
- Student profile.
- Country/timezone.
- Learning goals.
- Favorites.
- Wallet.
- Referral code.
- Student dashboard.
- Booking history.
- Learning Plan view.
- Homework view.
- Reviews.
- Support cases.

## **Instructor Checklist**

- Instructor registration.
- Instructor application.
- Instructor profile.
- Experience.
- Education.
- Subjects taught.
- Languages.
- KYC/documents.
- Admin approval.
- Public profile.
- Availability.
- Upcoming lessons.
- Homework assignment.
- Earnings.
- Withdrawal request.
- Reviews/feedback.
- Support cases.

## **Academic Checklist**

- Academic categories.
- Subjects.
- Education levels.
- Skill levels.
- Curriculum.
- Modules.
- Topics.
- Subtopics.
- Learning outcomes.
- Learning roadmaps.
- Learning Plans.
- Homework.
- Resources.

## **Marketplace Checklist**

- Instructor listing.
- Subject filter.
- Education level filter.
- Language filter.
- Rating filter.
- Availability filter.
- Price display.
- Sort options.
- Instructor profile page.
- Favorite instructor.
- Waitlist.
- Recommended instructors, future.

## **Booking Checklist**

- Instructor availability.
- Slot generation.
- Timezone conversion.
- Demo booking.
- Paid booking.
- Recurring booking.
- Slot reservation.
- Reservation expiry.
- Payment linkage.
- Booking confirmation.
- Cancellation.
- Reschedule.
- No-show.
- Technical issue.
- Auto-completion.
- Review trigger.
- Settlement trigger.

## **Meeting Checklist**

- Meeting provider abstraction.
- Google Meet or Zoom configuration.
- Platform-owned meeting link.
- Meeting creation job.
- Meeting retry.
- Meeting link visibility.
- Join access rules.
- Admin observer.
- Recording readiness.
- Attendance readiness.
- Technical issue reporting.

## **Wallet & Payment Checklist**

- Wallet account.
- Wallet ledger.
- Recharge.
- Razorpay checkout.
- Payment verification.
- Webhook/callback.
- Wallet credit.
- Wallet debit.
- Refund credit.
- Referral credit.
- Promotional credit.
- Wallet statement.
- Invoice/receipt.
- Reconciliation report.
- Idempotency keys.

## **Instructor Earnings Checklist**

- Pay rule configuration.
- Earning creation.
- Demo compensation policy.
- Paid lesson earning.
- Recurring lesson earning.
- Earning hold.
- Settlement delay.
- Withdrawable balance.
- Withdrawal request.
- Withdrawal approval.
- Withdrawal rejection.
- Paid marking.
- Instructor earning dashboard.
- Finance report.

## **Engagement Checklist**

- Notification templates.
- Email notifications.
- WhatsApp notifications.
- SMS notifications.
- In-app notifications.
- Delivery logs.
- Lesson reminders.
- Low wallet reminders.
- Controlled messaging.
- Message reporting.
- Reviews.
- Ratings.
- Feedback.
- Quality alerts.
- Referral rewards.
- Promotional credits.

## **Operations Checklist**

- Reports dashboard.
- Finance dashboard.
- Operations dashboard.
- Instructor quality dashboard.
- Support case management.
- Dispute handling.
- Audit log viewer.
- Settings audit.
- Financial audit.
- Booking audit.
- Queue monitoring.
- Failed job monitoring.
- Scheduled job monitoring.
- Provider failure alerts.
- System health dashboard.

# **7\. Critical Technical Rules for Developers**

## **Financial Rules**

- Wallet must be ledger-based.
- Never directly overwrite wallet balance without ledger history.
- Every financial action must have reference and audit log.
- Payment callback must be idempotent.
- Refunds go to wallet in Version 1.
- Instructor pay and student price must remain separate.
- Instructor must not see student price or platform margin.
- Manual financial adjustment requires reason.

## **Booking Rules**

- Slot must be revalidated at confirmation.
- Booking must prevent double booking.
- Reservation must expire automatically.
- Paid booking requires payment or wallet debit.
- Meeting link should be generated only after booking confirmation.
- Cancellation and reschedule must follow configured rules.
- No-show must use evidence and grace period.
- Booking lifecycle must be auditable.

## **Communication Rules**

- Critical notifications must be queued and logged.
- Student-instructor messaging requires valid learning/booking relationship.
- Off-platform contact sharing is prohibited by policy.
- Meeting links must not be public.
- Notification failures must be visible.

## **Admin Rules**

- Admin access must use roles and permissions.
- Sensitive actions require explicit permissions.
- Financial actions require reason.
- Report exports must be permission-controlled.
- Role and permission changes must be audit logged.
- Super Admin access should be limited.

## **Performance Rules**

- Admin tables must be paginated.
- Reports should use filters and queued exports.
- Public pages should be cacheable.
- Availability generation should be optimized.
- Critical jobs should be separated from low-priority jobs.
- Heavy operations should not block user workflows.

# **8\. Recommended First Claude Code Prompt**

Use this prompt to start implementation safely:

You are working on a Laravel 13 + Filament v4 + MySQL enterprise online learning marketplace named STEM Learning.

We have completed the SRS Book 2. Start Phase 1 implementation only.

Goal:

Build the foundation for admin operations, settings, countries, CMS readiness, roles/permissions, and audit logging.

Tech rules:

\- Use domain-driven folder structure under app/.

\- Keep controllers and Filament resources thin.

\- Put business logic in Actions/Services.

\- Use policies and Spatie permissions.

\- Use Filament Shield where appropriate.

\- Use migrations with proper indexes and foreign keys.

\- Use enums for statuses/types.

\- Use audit/activity logging for sensitive actions.

\- Do not implement booking/payment/wallet yet.

\- Do not create unnecessary module packages.

\- Use MySQL-compatible migrations.

Phase 1 scope:

1\. Admin user foundation

2\. Role and permission groups

3\. Country master

4\. Currency master

5\. Global settings skeleton

6\. Feature flag skeleton

7\. CMS page skeleton if not already existing

8\. FAQ category/item skeleton

9\. Audit/activity log foundation

10\. Basic admin dashboard cards

Deliverables:

\- Folder structure

\- Migrations

\- Models

\- Enums

\- Policies

\- Filament resources

\- Seeders for default roles/permissions/countries/currencies/settings

\- Tests for permissions and basic CRUD

\- Final audit of files changed

Important:

Before coding, inspect the existing app structure, installed packages, Filament panel, User model, role/permission setup, and existing CMS/settings code. Reuse existing foundations where available. Do not duplicate existing systems.

# **9\. Recommended Development Order**

The safest development order is:

1\. Admin foundation

2\. Countries/currencies/settings

3\. CMS/FAQ/legal pages

4\. Student/instructor profiles

5\. Academic framework

6\. Marketplace discovery

7\. Availability

8\. Booking

9\. Wallet/payment

10\. Meetings

11\. Earnings/withdrawals

12\. Notifications/messaging

13\. Reviews/referrals

14\. Reports/support/audit/operations

Do not start with payment or booking before identity, roles, settings, countries, student/instructor profiles, and academic structure are stable.

# **10\. Final Implementation Readiness Status**

## **SRS Status**

**Ready for technical architecture and phased implementation.**

## **Recommended Next Document**

Before coding all features, create:

Technical Architecture Blueprint

It should define:

- Laravel folder structure
- Database modules
- Service contracts
- Domain boundaries
- Queue design
- Event/listener design
- Permission strategy
- Policy strategy
- Filament resource plan
- Livewire frontend plan
- Testing strategy
- Deployment strategy

## **Recommended First Build**

Start with:

Phase 1 - Platform Foundation

Do not start directly with booking or payments.

# **11\. Final Book 2 Summary**

Book 2 defines a complete functional and operational blueprint for STEM Learning.

It covers the full lifecycle from public discovery to instructor approval, student booking, online lesson delivery, wallet payment, instructor earnings, referrals, reviews, support, reporting, admin operations, system monitoring, and reliability.

The platform is designed as:

- India-first for Version 1
- INR/Razorpay-first financially
- Global-ready structurally
- Instructor marketplace-driven
- Wallet-led for refunds and recurring lessons
- Admin-configurable
- Audit-safe
- Queue-driven
- SEO-ready
- Enterprise-ready
- Scalable for future US/UK/global expansion

This SRS can now be used as the baseline for implementation prompts, database planning, technical architecture, and phased Laravel development.