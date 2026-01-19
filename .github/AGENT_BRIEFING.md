# Agent Briefing: PHPArm Implementation Coordination

**Date:** January 18, 2026  
**Project:** PHPArm - Automotive Repair Shop & Towing ERP  
**Coordinator:** GitHub Copilot

---

## Project Context

You are assisting with the implementation and coordination of a sophisticated automotive repair management ERP system. The system has two main audiences:

1. **Automotive Repair Shops** - Core workflow: Estimate → Workorder → Invoice
2. **Roadside Assistance/Towing Companies** - Dispatch, driver management, job allocation

The codebase is primarily **PHP 8.1 (backend)** and **React 19 (frontend)**, with 92 database migrations representing ~2 years of development.

---

## Current Situation

### What's Complete ✅
- Core estimate/workorder/invoice workflow (80% complete)
- 90+ database tables properly structured
- Dispatch system for towing companies
- Parts management & inventory
- User management, financial tracking, CMS
- Technician productivity tools

### What's Incomplete or Uncertain ❓
1. **Sub-estimate workflow** - Database ready, but frontend may be incomplete
2. **Legal compliance for e-signing** - Tables exist, but consent flow may be missing
3. **Security & authorization** - No comprehensive audit completed
4. **Test coverage** - Insufficient for production release
5. **Vue→React migration** - Technically done, but not tested end-to-end

### Critical Path (Next 2 Weeks)
1. **Sub-estimate workflow verification & completion** (HIGH PRIORITY)
2. **Security audit & fixes** (HIGH PRIORITY)
3. **Comprehensive testing** (HIGH PRIORITY)
4. **Code quality improvements** (MEDIUM PRIORITY)
5. **Deployment preparation** (MEDIUM PRIORITY)

---

## Your Role & Responsibilities

### As Coordinator
Your job is to:
1. **Understand** the current state of the codebase and features
2. **Identify** gaps, bugs, and risks
3. **Delegate** specific tasks to specialized agents
4. **Track** progress and blockers
5. **Ensure** code quality and security standards
6. **Coordinate** communication and knowledge sharing

### Key Constraints
- Do NOT make large code changes yourself (delegate to Feature/Code Review agents)
- DO read code to understand architecture and find issues
- DO create detailed task descriptions for agents
- DO verify agent work meets quality standards
- DO update documentation as you learn new details

---

## Critical Tasks This Week

### Task 1: Security Audit Briefing (Delegate to Code Review Agent)
**Objective:** Identify all security vulnerabilities before production

Create a detailed briefing asking Code Review Agent to:
1. Audit authentication layer (JwtService.php)
   - Token expiry & refresh mechanism
   - Token revocation handling
   - Secure storage (HTTPOnly cookies)
   
2. Audit authorization layer (AccessGate)
   - Every API endpoint enforces role checks
   - No privilege escalation paths
   - Proper permission inheritance
   
3. Verify input validation
   - All DTOs sanitize input
   - SQL prepared statements throughout
   - Type coercion safe
   
4. Check encryption & hashing
   - Passwords use bcrypt/argon2
   - PII encrypted at rest (if applicable)
   - HTTPS enforcement
   
5. Rate limiting & abuse prevention
   - Check if implemented (likely missing)
   - Recommend implementation
   
6. Identify top 10 security issues + fixes

**Expected Output:** Security audit report with prioritized fixes

---

### Task 2: Sub-Estimate Workflow Verification (Delegate to Feature Implementation Agent)
**Objective:** Verify end-to-end sub-estimate workflow OR implement missing pieces

Create a detailed briefing asking Feature Implementation Agent to:

1. **Inspection Phase:**
   - [ ] Read WorkorderDetail.jsx - does it show estimate details?
   - [ ] Read EstimateController.php - can sub-estimates be created?
   - [ ] Verify sub_estimate table field exists in estimate schema
   
2. **Creation Phase:**
   - [ ] Verify sub-estimate creation works via API
   - [ ] Test: Create parent estimate → Approve → Create workorder → Create sub-estimate
   - [ ] Verify sub-estimate shows parent_estimate_id + workorder_id
   
3. **Approval Phase:**
   - [ ] Check EstimateDetail.jsx - does it show parent + sub-estimates?
   - [ ] **If NOT:** Build UI to show sub-estimates on approval page
   - [ ] Test: Approve some sub-estimate jobs, reject others
   
4. **Merge Phase:**
   - [ ] Verify final invoice includes jobs from approved sub-estimates
   - [ ] Check InvoiceService.php or WorkorderService.php for merge logic
   - [ ] Test: Full approval → workorder → invoice with sub-estimate jobs
   
5. **Rejection Phase:**
   - [ ] Test: Reject sub-estimate, workorder continues normally
   - [ ] Verify audit trail shows rejection with reason

6. **Database Verification:**
   - Show schema for: estimates, estimate_jobs, estimate_items
   - Confirm fields: estimate_type, parent_estimate_id, workorder_id

**Expected Output:**
- Verification report (feature complete? any gaps?)
- If gaps: List of missing components + estimates to build
- Test results for all 5 scenarios from WORKFLOW_IMPLEMENTATION_PLAN.md

---

### Task 3: Test Coverage Plan (Create for yourself as coordinator)
**Objective:** Establish comprehensive testing strategy

You should:
1. Review existing tests (in /tests directory)
2. Run them locally, document results
3. Create a test plan covering:
   ```
   CRITICAL (P0):
   - [ ] Estimate full approval → Workorder creation → Invoice generation
   - [ ] Estimate partial approval (some jobs rejected)
   - [ ] Estimate rejection (full)
   - [ ] Sub-estimate creation + approval + merge
   - [ ] Authorization: unauthorized role tries to access estimate/workorder
   
   HIGH (P1):
   - [ ] Technician clocking per job (granular labor tracking)
   - [ ] Parts cart creation → PartsTech sync → order approval → AP entry
   - [ ] Barcode scan → part lookup with vehicle compatibility
   - [ ] Dispatch job creation → waterfall assignment → driver acceptance
   - [ ] Financial: invoice calculation with tax, fees, discounts
   
   MEDIUM (P2):
   - [ ] QC checklist requirement before invoicing
   - [ ] GOA (Going Out on Approval) field transitions
   - [ ] E-signature capture with consent + IP logging
   - [ ] Stock order receipt → AP ledger creation
   - [ ] Inventory transfers with bin location
   ```

4. Document which tests exist, which are missing
5. Identify which can be automated vs. manual

---

## How to Delegate Tasks

### Format for Feature Implementation Agent

```markdown
## Feature: [Name]
**Priority:** [P0/P1/P2]
**Estimated Effort:** [X hours]
**Due Date:** [Date]

### Objective
[Clear 1-line goal]

### Acceptance Criteria
- [ ] Criterion 1
- [ ] Criterion 2
- [ ] All code follows style guide
- [ ] Tests pass (new + existing)
- [ ] No breaking changes

### Technical Details
[File paths, API endpoints, database tables, etc.]

### Background
[Why this matters, who uses it, business impact]

### Questions to Answer
- [ ] Question 1?
- [ ] Question 2?

### Deliverables
1. Code merged to main branch
2. Test results documented
3. Any new API endpoints documented
```

### Format for Code Review Agent

```markdown
## Code Review: [Scope]
**Priority:** [P0/P1/P2]
**Files to Review:** [List file paths]
**Focus Areas:** [Security, Performance, Quality, etc.]

### Checklist
- [ ] Security: No vulnerabilities
- [ ] Performance: No N+1 queries
- [ ] Quality: Code is DRY, well-structured
- [ ] Testing: Adequate coverage
- [ ] Documentation: Clear, up-to-date

### Red Flags to Watch For
- Unvalidated user input
- Missing authorization checks
- Hardcoded credentials/secrets
- Performance issues (large loops, N+1 queries)
- Incomplete error handling

### Questions to Answer
1. Is this code production-ready?
2. What are top 3 security concerns?
3. What's the estimated refactoring effort?

### Deliverables
1. Detailed review comments (inline)
2. Summary of issues (prioritized)
3. Refactoring recommendations
```

---

## Key Files to Reference

### Documentation
- `.github/IMPLEMENTATION_STATUS.md` - Comprehensive feature list + blockers
- `.github/COORDINATOR_DASHBOARD.md` - This week's action items
- `WORKFLOW_IMPLEMENTATION_PLAN.md` - Detailed technical specifications
- `feature-path.md` - Completed features + roadmap

### Code
**Backend Models:**
- `src/Models/Workorder.php`
- `src/Models/Estimate.php`
- `src/Models/Invoice.php`
- `src/Models/User.php`

**Backend Services:**
- `src/Services/Workorder/WorkorderService.php`
- `src/Services/Estimate/EstimateService.php` (if exists)
- `src/Services/Invoice/InvoiceService.php` (if exists)
- `src/Support/Auth/JwtService.php`

**Frontend Components:**
- `src/react/views/workorders/WorkorderDetail.jsx`
- `src/react/views/estimates/EstimateDetail.jsx` (if exists)
- `src/services/workorder.service.js`
- `src/services/estimate.service.js` (if exists)

**Database:**
- `database/migrations/043_create_workorders.sql`
- `database/migrations/044_add_approval_audit_log.sql`
- `database/migrations/040_add_estimate_rejection_and_item_status.sql`

---

## Communication Protocol

### Daily Standup (9:00 AM)
Each agent reports:
- What was completed yesterday
- What's in progress today
- Any blockers encountered
- Revised time estimates

### Async Updates
If an agent finds something important:
- Create a GitHub issue with full context
- Label it appropriately (bug, security, enhancement)
- Tag coordinator for immediate review

### Weekly Summary (Friday 4 PM)
Coordinator prepares report with:
- Completed work
- In-progress items
- New blockers discovered
- Risk assessment update
- Next week's priorities

---

## Success Criteria

### This Week
- [ ] Security audit completed + top 10 issues identified
- [ ] Sub-estimate workflow verified or gaps documented
- [ ] Existing tests passing
- [ ] Test plan created for critical paths
- [ ] No new critical security issues found

### This Sprint (2 Weeks)
- [ ] All P0 issues resolved
- [ ] Sub-estimate workflow complete & tested
- [ ] Security fixes implemented
- [ ] 80% test coverage for critical paths
- [ ] Deployment runbook created

### Before Production Release
- [ ] 90%+ test coverage
- [ ] Zero critical security issues
- [ ] All P0 & P1 issues resolved
- [ ] Security audit sign-off
- [ ] Performance benchmarked
- [ ] Deployment & rollback procedures tested

---

## Frequently Asked Questions

**Q: Where do I report issues?**
A: Create a GitHub issue in the main repo with:
- Clear title
- Steps to reproduce
- Current behavior
- Expected behavior
- Environment (PHP version, MySQL version, browser, etc.)

**Q: How do I prioritize between multiple tasks?**
A: Follow this order:
1. P0 (critical path blockers) - must finish
2. P1 (high impact features) - finish if possible
3. P2 (nice-to-have improvements) - queue for next sprint

**Q: What if I discover new issues?**
A: Document them and notify coordinator immediately:
1. Is it blocking other work? → P0
2. Does it affect security/data? → P0
3. Does it affect user workflows? → P1
4. Code quality/performance only? → P2

**Q: How much detail should I document?**
A: Enough that someone unfamiliar can pick up the work:
- WHY you made changes (business reason)
- WHAT changed (files, functions, logic)
- HOW it works (clear explanation)
- TESTS that verify it works

---

## Getting Started

### Day 1
1. Review `.github/IMPLEMENTATION_STATUS.md` (30 min)
2. Read `WORKFLOW_IMPLEMENTATION_PLAN.md` sections 1-3 (30 min)
3. Check out critical code files (30 min):
   - `src/Services/Workorder/WorkorderController.php`
   - `src/Services/Workorder/WorkorderService.php`
   - `src/react/views/workorders/WorkorderDetail.jsx`
4. Start delegating Task 1 (Security Audit) to Code Review Agent

### Day 2-3
1. Run existing tests locally
2. Start Task 2 (Sub-estimate verification)
3. Review findings from Code Review Agent
4. Create detailed test plan

### End of Week
1. Consolidate findings
2. Update blockers & risk register
3. Prioritize next sprint
4. Prepare weekly summary

---

## Questions?

For clarifications on the project, architecture, or specific features:
- Reference the documentation files listed above
- Check the code comments and commit history
- Ask the agents specific questions in task descriptions
- Document your findings in GitHub issues

Your role is to **understand the system deeply**, **identify gaps**, and **coordinate specialists** to close them.

**You've got this. Let's make PHPArm production-ready! 🚀**
