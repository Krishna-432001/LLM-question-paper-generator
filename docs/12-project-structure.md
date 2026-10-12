# 12 — Project Structure

Covers required outputs **24 (Laravel folder structure)** and **25 (Next.js folder
structure)**.

---

## 12.1 Repository layout (monorepo)

```
examforge/
├── apps/
│   ├── api/                 # Laravel 11
│   ├── web/                 # Next.js 15
│   └── pdf/                 # Typst render microservice (Rust/Node wrapper)
├── packages/
│   ├── contracts/           # OpenAPI spec + generated TS types (shared)
│   └── ui/                  # shared React components (if a second app appears)
├── infra/
│   ├── terraform/           # VPC, RDS, ElastiCache, ECS/EKS, R2, secrets
│   ├── docker/              # Dockerfiles: api, worker, pdf, ocr-sandbox
│   ├── k8s/                 # manifests / helm values per env
│   └── grafana/             # dashboards + alert rules as code
├── docs/                    # this specification
└── .github/workflows/       # CI: test, static analysis, deploy, migration gate
```

---

## 12.2 Laravel (`apps/api`)

```
apps/api/
├── app/
│   ├── Actions/                          # application layer — the real business logic
│   │   ├── Sources/
│   │   │   ├── CreateSourceAction.php
│   │   │   ├── StartIngestionAction.php
│   │   │   └── RestartFromStageAction.php
│   │   ├── Questions/
│   │   │   ├── PlanGenerationRunAction.php
│   │   │   ├── GenerateQuestionBatchAction.php
│   │   │   ├── ValidateQuestionBatchAction.php
│   │   │   ├── DeduplicateCandidatesAction.php
│   │   │   ├── ScoreAndSelectAction.php
│   │   │   ├── ApproveQuestionAction.php
│   │   │   └── TranslateQuestionsAction.php
│   │   ├── Syllabus/    ExtractSyllabusAction.php · PublishSyllabusVersionAction.php
│   │   ├── Plans/       GenerateStudyPlanAction.php · ReplanAction.php
│   │   ├── Tests/       AssembleTestAction.php · EvaluateAttemptAction.php
│   │   ├── Prediction/  ComputePredictionScoresAction.php · BuildModelPapersAction.php
│   │   ├── Documents/   RenderDocumentAction.php · ShareDocumentAction.php
│   │   └── Billing/     CheckoutAction.php · ApplyWebhookAction.php
│   │
│   ├── Domain/                           # pure domain logic, framework-light
│   │   ├── Questions/
│   │   │   ├── Services/  QualityScorer.php · DuplicateDetector.php
│   │   │   │              SelectionEngine.php · DifficultyCalibrator.php
│   │   │   ├── DTOs/      QuestionCandidate.php · ValidationReport.php · SelectionSpec.php
│   │   │   └── Enums/     QuestionStatus.php · Difficulty.php · RejectionCode.php
│   │   ├── Rag/           ChunkPlanner.php · Retriever.php · ContextPacker.php
│   │   ├── Syllabus/      HierarchyNormalizer.php · SyllabusDiffer.php
│   │   ├── Plans/         PlanScheduler.php · SpacedRepetition.php · EffortEstimator.php
│   │   ├── Prediction/    FrequencyAnalyzer.php · PredictionScorer.php · Calibrator.php
│   │   ├── Translation/   PlaceholderMasker.php · TerminologyChecker.php
│   │   │                  TranslationValidator.php
│   │   ├── Documents/     DocumentComposer.php · ContentHasher.php
│   │   ├── Billing/       EntitlementService.php · UsageMeter.php · QuotaReserver.php
│   │   └── Tenancy/       TenantContext.php · TenantResolver.php
│   │
│   ├── Infrastructure/                   # adapters — the only place vendors appear
│   │   ├── Ai/
│   │   │   ├── AiGateway.php             # the single choke point
│   │   │   ├── Contracts/  LlmClient.php · EmbeddingProvider.php
│   │   │   ├── Providers/  ClaudeClient.php · FallbackClient.php · FakeLlmClient.php
│   │   │   ├── Embeddings/ E5EmbeddingProvider.php · FakeEmbeddingProvider.php
│   │   │   ├── Prompts/    PromptRegistry.php · PromptRenderer.php
│   │   │   ├── Schema/     SchemaValidator.php · RepairAttempt.php
│   │   │   ├── Cache/      ResponseCache.php
│   │   │   └── Cost/       CostCalculator.php · BudgetGuard.php
│   │   ├── Ocr/            DocumentAiEngine.php · TesseractEngine.php · OcrEngine.php
│   │   ├── Pdf/            TypstRenderer.php · GotenbergRenderer.php · PdfRenderer.php
│   │   ├── Storage/        ObjectStorage.php · SignedUrlFactory.php
│   │   ├── Payments/       RazorpayGateway.php · WebhookVerifier.php
│   │   └── Antivirus/      ClamAvScanner.php
│   │
│   ├── Jobs/                             # orchestration only — no business logic
│   │   ├── TrackedJob.php
│   │   ├── Ingestion/  ScanForMalware.php · ExtractText.php · NormalizeText.php
│   │   │               DetectStructure.php · ChunkSource.php · EmbedChunks.php
│   │   │               MapToSyllabus.php
│   │   ├── Generation/ PlanGenerationRun.php · GenerateQuestionBatch.php
│   │   │               ValidateQuestionBatch.php · DeduplicateBatch.php
│   │   │               ScoreAndSelect.php · FinalizeGeneration.php
│   │   ├── Syllabus/   AnalyzeSyllabus.php
│   │   ├── Papers/     ExtractPaperQuestions.php · ClassifyPaperQuestions.php
│   │   ├── Prediction/ ComputePredictionScores.php · BuildPredictionPaper.php
│   │   ├── Plans/      GenerateStudyPlan.php · AssembleTest.php · ReplanUser.php
│   │   ├── Translation/TranslateQuestionBatch.php · ValidateTranslationBatch.php
│   │   ├── Documents/  RenderDocument.php
│   │   └── Maintenance/RollupUsage.php · RefreshTopicStats.php
│   │                   RecalibrateDifficulty.php · TuneOvergenerationFactors.php
│   │
│   ├── Models/          Organization.php · User.php · Exam.php · ExamTopic.php
│   │                    Syllabus*.php · Source.php · Book*.php · SourceChunk.php
│   │                    Question.php · QuestionOption.php · QuestionTranslation.php
│   │                    QuestionSource.php · PreviousPaper*.php · PredictionScore.php
│   │                    StudyPlan*.php · Test*.php · GeneratedDocument.php
│   │                    AiJob.php · AiInvocation.php · PromptVersion.php
│   │                    Subscription.php · PlanQuota.php · UsageRecord.php
│   │       └── Concerns/ BelongsToOrganization.php · HasUuidV7.php · Versionable.php
│   │
│   ├── Http/
│   │   ├── Controllers/Api/V1/   (thin: validate → authorize → Action → Resource)
│   │   │   ├── Auth/ · Catalogue/ · Sources/ · Questions/ · Syllabus/
│   │   │   ├── Plans/ · Tests/ · Documents/ · Predictions/ · Analytics/
│   │   │   ├── Jobs/ · Billing/ · Admin/
│   │   ├── Requests/             GenerateQuestionsRequest.php · CreateWeeklyPlanRequest.php …
│   │   ├── Resources/            QuestionResource.php · TestResource.php · JobResource.php …
│   │   └── Middleware/           ResolveTenant.php · EnsureQuota.php · Idempotency.php
│   │                             RequireMfaForAdmin.php · AuditRequest.php
│   ├── Policies/                 QuestionPolicy.php · TestPolicy.php · DocumentPolicy.php …
│   ├── Console/Commands/         examforge:ingest-book · examforge:generate
│   │                             examforge:backfill-embeddings · examforge:eval-prompt
│   ├── Events/ · Listeners/ · Notifications/
│   ├── Exceptions/               QuotaExceededException.php · ProviderUnavailableException.php
│   │                             ValidationGauntletException.php
│   └── Support/                  Money.php · Tokenizer.php · TextNormalizer.php
│
├── config/    ai.php · rag.php · generation.php · pdf.php · tenancy.php · quotas.php
├── database/
│   ├── migrations/               (one per table, plus RLS/trigger/partition migrations)
│   ├── seeders/                  LanguageSeeder · TnpscG4Seeder · PromptSeeder
│   │                             PlanSeeder · CanonicalTopicSeeder
│   └── factories/
├── routes/    api.php · api_admin.php · web.php · console.php · channels.php
├── tests/
│   ├── Unit/                     scorers, schedulers, maskers, calibrators
│   ├── Feature/                  endpoint contracts, policies, quotas
│   ├── Integration/              pipelines end-to-end with fake LLM
│   ├── Tenancy/                  the cross-tenant leak suite (must always pass)
│   └── Fixtures/                 recorded LLM responses, sample PDFs, golden sets
└── storage/app/                  (temp only — nothing durable lives on the app disk)
```

**Structural rules enforced in CI**
1. No vendor SDK import outside `app/Infrastructure/`.
2. No `DB::` or Eloquent query inside `app/Domain/` (domain is persistence-free).
3. No business logic inside `app/Jobs/` (a Job may only call Actions).
4. Every `Api/V1` controller method has a matching Policy call and a FormRequest.
5. Every model with `organization_id` uses `BelongsToOrganization`.

---

## 12.3 Next.js (`apps/web`)

```
apps/web/
├── src/
│   ├── app/
│   │   ├── (marketing)/                    # public, SSG/ISR, SEO-critical
│   │   │   ├── page.tsx                    # home
│   │   │   ├── exams/[slug]/page.tsx       # per-exam landing (organic acquisition)
│   │   │   ├── pricing/ · features/ · about/ · faq/ · blog/[slug]/
│   │   │   └── layout.tsx
│   │   ├── (auth)/
│   │   │   ├── login/ · register/ · forgot-password/ · verify-email/ · mfa/
│   │   ├── (student)/
│   │   │   ├── layout.tsx                  # sidebar shell, org switcher, usage meter
│   │   │   ├── dashboard/page.tsx
│   │   │   ├── exams/page.tsx
│   │   │   ├── study-plan/
│   │   │   │   ├── daily/page.tsx
│   │   │   │   ├── three-day/page.tsx
│   │   │   │   ├── weekly/page.tsx
│   │   │   │   ├── monthly/page.tsx
│   │   │   │   └── model-test/page.tsx
│   │   │   ├── question-bank/page.tsx
│   │   │   ├── tests/
│   │   │   │   ├── page.tsx
│   │   │   │   ├── daily/ · three-day/ · weekly/ · monthly/
│   │   │   │   ├── model/page.tsx
│   │   │   │   ├── prediction/page.tsx
│   │   │   │   └── [testId]/attempt/page.tsx      # the exam runner
│   │   │   ├── results/page.tsx · results/[attemptId]/page.tsx
│   │   │   ├── analytics/page.tsx
│   │   │   ├── downloads/page.tsx
│   │   │   ├── subscription/page.tsx
│   │   │   ├── language/page.tsx
│   │   │   └── profile/page.tsx
│   │   ├── (admin)/admin/
│   │   │   ├── layout.tsx                  # requires admin role + MFA
│   │   │   ├── page.tsx                    # overview
│   │   │   ├── exams/ · syllabus/ · books/ · previous-papers/ · current-affairs/
│   │   │   ├── questions/
│   │   │   │   ├── page.tsx                # faceted browse
│   │   │   │   ├── review/page.tsx         # reviewer workstation
│   │   │   │   ├── rejected/page.tsx · duplicates/page.tsx · health/page.tsx
│   │   │   ├── jobs/page.tsx · jobs/[id]/page.tsx
│   │   │   ├── predictions/ · languages/ · users/ · organizations/
│   │   │   ├── subscriptions/ · payments/
│   │   │   ├── prompts/page.tsx · prompts/[name]/page.tsx
│   │   │   ├── models/ · usage/ · analytics/ · audit-logs/ · settings/
│   │   ├── s/[token]/page.tsx               # public shared-document resolver
│   │   ├── api/                             # BFF: auth cookie proxy, webhooks-in only
│   │   └── layout.tsx · error.tsx · not-found.tsx
│   │
│   ├── components/
│   │   ├── ui/                              # shadcn primitives
│   │   ├── layout/       Sidebar · Topbar · OrgSwitcher · UsageMeter · LanguageToggle
│   │   ├── plan/         PlanCalendar · DayCard · TopicItem · ProgressRing · PlanModeTabs
│   │   ├── test/         TestRunner · QuestionCard · OptionList · Timer
│   │   │                 QuestionPalette · SubmitDialog · OfflineBanner
│   │   ├── results/      ScoreCard · TopicBreakdown · SolutionList · CitationBadge
│   │   ├── analytics/    MasteryHeatmap · AccuracyTrend · TimePerQuestion · WeakTopics
│   │   ├── documents/    DocumentCard · PdfViewer · ShareDialog · RegenerateDialog
│   │   ├── jobs/         JobProgress · JobTimeline · JobCostBadge
│   │   └── admin/        ReviewWorkstation · SourcePane · StructureEditor
│   │                     OcrCorrector · PromptDiff · BankHealthGrid · CoverageGapTable
│   │
│   ├── lib/
│   │   ├── api/          client.ts (typed fetch) · endpoints/*.ts (generated from OpenAPI)
│   │   ├── auth/         session.ts · guards.ts
│   │   ├── hooks/        useJob.ts (SSE+poll) · useQuota.ts · useOfflineAttempt.ts
│   │   │                 useTenant.ts · useDebounce.ts
│   │   ├── i18n/         config.ts · dictionaries/{en,ta,te,ml,hi}.json
│   │   ├── offline/      idb.ts · attemptQueue.ts · serviceWorker.ts
│   │   └── utils/        format.ts · date.ts (Asia/Kolkata) · cn.ts
│   │
│   ├── stores/           attemptStore.ts (zustand) · uiStore.ts
│   ├── types/            generated from packages/contracts (never hand-written)
│   └── styles/           globals.css · fonts/ (Noto subsets, self-hosted)
├── public/               manifest.json · icons · offline.html
├── next.config.ts · tailwind.config.ts · middleware.ts (auth + locale routing)
└── tests/                unit (vitest) · e2e (playwright: attempt flow, offline, admin review)
```

**Rendering strategy**

| Route group | Strategy | Why |
|---|---|---|
| `(marketing)` | SSG + ISR (1 h) | SEO on "TNPSC Group IV syllabus" queries is a primary acquisition channel |
| `(auth)` | CSR | Small, interactive |
| `(student)` dashboards | RSC + streaming | Fast first paint on slow networks; data fetched server-side |
| Test runner | Client component | Needs timer, offline buffer, keyboard nav, no server round-trips per answer |
| `(admin)` | CSR behind auth | No SEO value; heavy interactivity |

**Key frontend decisions**
- Server Components fetch through the same typed client, with the session cookie forwarded
  — no duplicate data-fetching code paths.
- The **test runner never calls the API per answer**: answers go to a local store and are
  flushed in batches (every 10 answers or 30 s), which is what makes it usable on a 3G
  connection in a village exam-prep centre.
- Types are generated from the OpenAPI contract in `packages/contracts`; a backend change
  that breaks the frontend fails CI at type-check, not in production.
- Tamil/Indic fonts are self-hosted and subset — no third-party font CDN (privacy + speed).
