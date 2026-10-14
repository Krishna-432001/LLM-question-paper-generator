"use client";

import { createContext, useCallback, useContext, useSyncExternalStore, type ReactNode } from "react";

/**
 * Interface strings for the student app, in English and Tamil.
 *
 * Question content is translated server-side and validated per question; this
 * covers only the app's own labels. Tamil strings should be checked by a
 * native reviewer before launch, as the roadmap requires for content.
 */

export type Locale = "en" | "ta";

const STRINGS = {
  en: {
    "nav.today": "Today",
    "nav.plans": "Plans",
    "nav.tests": "Tests",
    "nav.progress": "Progress",
    "nav.downloads": "Downloads",
    "nav.logout": "Log out",
    "lang.label": "Language",
    "common.loading": "Loading…",
    "common.save": "Save",
    "common.cancel": "Cancel",
    "common.minutes": "min",
    "common.questions": "questions",
    "common.error": "Something went wrong.",
    "onboard.title": "Which exam are you preparing for?",
    "onboard.subtitle": "Everything in the app is tailored to this exam. You can change it later.",
    "onboard.continue": "Continue",
    "home.greeting": "Welcome back",
    "home.streak": "day streak",
    "home.today": "Today's plan",
    "home.noPlan": "You have no study plan yet.",
    "home.makePlan": "Make a plan",
    "home.quickTest": "Quick practice test",
    "home.quickTestHint": "20 questions, mixed difficulty",
    "home.recent": "Recent results",
    "home.noResults": "No tests taken yet.",
    "home.weak": "Topics to work on",
    "home.noWeak": "Take a few tests and your weak topics will show here.",
    "home.reviewDue": "topics due for revision",
    "home.disclaimer": "Practice questions are drawn from approved, source-cited material. Nothing here predicts or guarantees exam questions.",
    "plans.title": "Study plans",
    "plans.new": "New plan",
    "plans.mode": "Plan type",
    "plans.mode.daily": "Daily",
    "plans.mode.three-day": "3-day",
    "plans.mode.weekly": "Weekly",
    "plans.mode.monthly": "Monthly",
    "plans.mode.model-test": "Model test",
    "plans.examDate": "Exam date (optional)",
    "plans.hours": "Hours per day",
    "plans.offDays": "Days off",
    "plans.create": "Create plan",
    "plans.days": "days",
    "plans.coverage": "syllabus covered",
    "plans.open": "Open",
    "plans.dayTest": "Start this day's test",
    "plans.done": "Done",
    "plans.markDone": "Mark done",
    "plans.revision": "revision",
    "tests.title": "Tests",
    "tests.new": "New practice test",
    "tests.type": "Type",
    "tests.count": "Questions",
    "tests.duration": "Minutes",
    "tests.create": "Start test",
    "tests.resume": "Resume",
    "tests.retake": "Take again",
    "tests.best": "Best",
    "tests.none": "No tests yet.",
    "tests.noQuestions": "There are not enough approved questions for this yet. Try fewer questions or English.",
    "take.timeLeft": "Time left",
    "take.question": "Question",
    "take.of": "of",
    "take.prev": "Previous",
    "take.next": "Next",
    "take.review": "Mark for review",
    "take.clear": "Clear answer",
    "take.submit": "Submit test",
    "take.confirm": "Submit now? Unanswered questions score zero.",
    "take.answered": "answered",
    "take.offline": "You are offline. Your answers are saved on this device and will be sent when you submit.",
    "take.timeUp": "Time is up. Submitting your answers…",
    "result.title": "Result",
    "result.score": "Score",
    "result.accuracy": "Accuracy",
    "result.correct": "Correct",
    "result.wrong": "Wrong",
    "result.skipped": "Skipped",
    "result.byTopic": "By topic",
    "result.solutions": "Solutions",
    "result.answer": "Answer",
    "result.source": "Source",
    "progress.title": "Progress",
    "progress.tests": "Tests taken",
    "progress.answered": "Questions answered",
    "progress.accuracy": "Overall accuracy",
    "progress.trend": "Accuracy, last 30 days",
    "progress.mastery": "Topic mastery",
    "progress.strong": "Strong topics",
    "progress.empty": "No practice yet for this exam.",
    "downloads.title": "Downloads",
    "downloads.make": "Make PDFs",
    "downloads.paper": "Question paper",
    "downloads.key": "Answer key",
    "downloads.explanations": "Explanations",
    "downloads.plan": "Study plan",
    "downloads.none": "Nothing downloaded yet.",
    "auth.register": "Create account",
    "auth.name": "Your name",
    "auth.email": "Email",
    "auth.password": "Password",
    "auth.confirm": "Confirm password",
    "auth.passwordHint": "At least 10 characters, with letters and numbers.",
    "auth.haveAccount": "Already have an account? Log in",
    "paper.language": "Paper language",
    "paper.both": "Both",
    "paper.bothHint": "Every question in both languages, the app's language first.",
    "paper.notEnoughBoth": "Not enough questions exist in both languages yet. Choose one language or ask for fewer questions.",
    "downloads.languageNote": "Each PDF is printed in its test's own language — both languages for a bilingual test.",
    // Practice papers (PDF to timed test) — appended block.
    "nav.papers": "Upload paper",
    "papers.title": "Upload a question paper",
    "papers.subtitle": "A past paper, or any MCQ paper with options (A)–(D) or அ)–ஈ). It becomes a timed test you can take straight away.",
    "papers.file": "Question paper (PDF)",
    "papers.paperTitle": "Title",
    "papers.minutes": "Time limit (minutes)",
    "papers.minutesHint": "Leave blank for 1 minute per question",
    "papers.upload": "Read the paper",
    "papers.reading": "Reading the paper…",
    "papers.ocr": "This PDF is a scan, so its pages are being read with OCR. This can take a few minutes; you can leave this page and find the paper in your list later.",
    "papers.report": "What was read",
    "papers.parsed": "Questions read",
    "papers.skipped": "Skipped",
    "papers.keyFound": "Answer key",
    "papers.keyMissing": "Not found",
    "papers.languages": "Languages",
    "papers.pages": "Pages",
    "papers.warnings": "Please check",
    "papers.start": "Start timed test",
    "papers.mine": "Your uploaded papers",
    "papers.none": "No papers uploaded yet.",
    "papers.retake": "Take again",
    "papers.resume": "Resume",
    "papers.attempts": "attempts",
    "papers.best": "Best",
    "papers.keyed": "with answers",
    "papers.addKey": "Add answer key",
    "papers.keyHint": "One entry per question, e.g. 1-A 2-C 3-B (அ ஆ இ ஈ also work)",
    "papers.saveKey": "Save answer key",
    "papers.keySaved": "Questions updated",
    "papers.notRescored": "Earlier attempts are not re-scored. Take the paper again to be marked against this key.",
    "papers.unmatched": "Not in this paper",
    "papers.unscored": "No answer key — not scored",
    "papers.unscoredCount": "questions had no answer key, so they were left out of the score.",
    "papers.failed": "The paper could not be read.",
    "papers.chooseFile": "Choose a PDF first.",
    "papers.lang.en": "English",
    "papers.lang.ta": "Tamil",
    "paper.unavailable": "translation unavailable",
  },
  ta: {
    "nav.today": "இன்று",
    "nav.plans": "திட்டங்கள்",
    "nav.tests": "தேர்வுகள்",
    "nav.progress": "முன்னேற்றம்",
    "nav.downloads": "பதிவிறக்கங்கள்",
    "nav.logout": "வெளியேறு",
    "lang.label": "மொழி",
    "common.loading": "ஏற்றுகிறது…",
    "common.save": "சேமி",
    "common.cancel": "ரத்து",
    "common.minutes": "நிமி",
    "common.questions": "வினாக்கள்",
    "common.error": "ஏதோ தவறு நடந்தது.",
    "onboard.title": "நீங்கள் எந்தத் தேர்வுக்குத் தயாராகிறீர்கள்?",
    "onboard.subtitle": "இந்தச் செயலியில் உள்ள அனைத்தும் இந்தத் தேர்வுக்கேற்ப அமையும். பின்னர் மாற்றலாம்.",
    "onboard.continue": "தொடர்க",
    "home.greeting": "மீண்டும் வருக",
    "home.streak": "நாள் தொடர்ச்சி",
    "home.today": "இன்றைய திட்டம்",
    "home.noPlan": "உங்களுக்கு இன்னும் படிப்புத் திட்டம் இல்லை.",
    "home.makePlan": "திட்டம் உருவாக்கு",
    "home.quickTest": "விரைவுப் பயிற்சித் தேர்வு",
    "home.quickTestHint": "20 வினாக்கள், கலந்த கடினம்",
    "home.recent": "சமீபத்திய முடிவுகள்",
    "home.noResults": "இன்னும் எந்தத் தேர்வும் எழுதவில்லை.",
    "home.weak": "கவனம் தேவைப்படும் தலைப்புகள்",
    "home.noWeak": "சில தேர்வுகள் எழுதிய பின் உங்கள் பலவீனமான தலைப்புகள் இங்கே தோன்றும்.",
    "home.reviewDue": "தலைப்புகள் மீள்பார்வைக்குத் தயார்",
    "home.disclaimer": "பயிற்சி வினாக்கள் அங்கீகரிக்கப்பட்ட, ஆதாரம் குறிப்பிடப்பட்ட பாடப்பகுதிகளிலிருந்து எடுக்கப்படுகின்றன. இவை தேர்வு வினாக்களை முன்கூட்டியே கூறுவதோ உறுதி செய்வதோ இல்லை.",
    "plans.title": "படிப்புத் திட்டங்கள்",
    "plans.new": "புதிய திட்டம்",
    "plans.mode": "திட்ட வகை",
    "plans.mode.daily": "தினசரி",
    "plans.mode.three-day": "3 நாள்",
    "plans.mode.weekly": "வாராந்திர",
    "plans.mode.monthly": "மாதாந்திர",
    "plans.mode.model-test": "மாதிரித் தேர்வு",
    "plans.examDate": "தேர்வு நாள் (விருப்பம்)",
    "plans.hours": "நாளொன்றுக்கு மணி நேரம்",
    "plans.offDays": "விடுமுறை நாட்கள்",
    "plans.create": "திட்டம் உருவாக்கு",
    "plans.days": "நாட்கள்",
    "plans.coverage": "பாடத்திட்டம் உள்ளடக்கம்",
    "plans.open": "திற",
    "plans.dayTest": "இந்நாளின் தேர்வைத் தொடங்கு",
    "plans.done": "முடிந்தது",
    "plans.markDone": "முடிந்ததாகக் குறி",
    "plans.revision": "மீள்பார்வை",
    "tests.title": "தேர்வுகள்",
    "tests.new": "புதிய பயிற்சித் தேர்வு",
    "tests.type": "வகை",
    "tests.count": "வினாக்கள்",
    "tests.duration": "நிமிடங்கள்",
    "tests.create": "தேர்வைத் தொடங்கு",
    "tests.resume": "தொடர்க",
    "tests.retake": "மீண்டும் எழுது",
    "tests.best": "சிறந்தது",
    "tests.none": "இன்னும் தேர்வுகள் இல்லை.",
    "tests.noQuestions": "இதற்குப் போதுமான அங்கீகரிக்கப்பட்ட வினாக்கள் இன்னும் இல்லை. குறைந்த வினாக்களோ ஆங்கிலமோ முயலவும்.",
    "take.timeLeft": "மீதமுள்ள நேரம்",
    "take.question": "வினா",
    "take.of": "/",
    "take.prev": "முந்தைய",
    "take.next": "அடுத்து",
    "take.review": "மீண்டும் பார்க்கக் குறி",
    "take.clear": "விடையை நீக்கு",
    "take.submit": "தேர்வைச் சமர்ப்பி",
    "take.confirm": "இப்போதே சமர்ப்பிக்கவா? விடையளிக்காத வினாக்களுக்கு மதிப்பெண் இல்லை.",
    "take.answered": "விடையளித்தவை",
    "take.offline": "இணைப்பு இல்லை. உங்கள் விடைகள் இந்தச் சாதனத்தில் சேமிக்கப்பட்டுள்ளன; சமர்ப்பிக்கும்போது அனுப்பப்படும்.",
    "take.timeUp": "நேரம் முடிந்தது. உங்கள் விடைகளைச் சமர்ப்பிக்கிறது…",
    "result.title": "முடிவு",
    "result.score": "மதிப்பெண்",
    "result.accuracy": "துல்லியம்",
    "result.correct": "சரி",
    "result.wrong": "தவறு",
    "result.skipped": "விடுபட்டவை",
    "result.byTopic": "தலைப்பு வாரியாக",
    "result.solutions": "தீர்வுகள்",
    "result.answer": "விடை",
    "result.source": "ஆதாரம்",
    "progress.title": "முன்னேற்றம்",
    "progress.tests": "எழுதிய தேர்வுகள்",
    "progress.answered": "விடையளித்த வினாக்கள்",
    "progress.accuracy": "மொத்தத் துல்லியம்",
    "progress.trend": "துல்லியம், கடந்த 30 நாட்கள்",
    "progress.mastery": "தலைப்புத் தேர்ச்சி",
    "progress.strong": "வலுவான தலைப்புகள்",
    "progress.empty": "இந்தத் தேர்வுக்கு இன்னும் பயிற்சி இல்லை.",
    "downloads.title": "பதிவிறக்கங்கள்",
    "downloads.make": "PDF உருவாக்கு",
    "downloads.paper": "வினாத்தாள்",
    "downloads.key": "விடைக்குறிப்பு",
    "downloads.explanations": "விளக்கங்கள்",
    "downloads.plan": "படிப்புத் திட்டம்",
    "downloads.none": "இன்னும் எதுவும் பதிவிறக்கவில்லை.",
    "auth.register": "கணக்கு உருவாக்கு",
    "auth.name": "உங்கள் பெயர்",
    "auth.email": "மின்னஞ்சல்",
    "auth.password": "கடவுச்சொல்",
    "auth.confirm": "கடவுச்சொல்லை உறுதிப்படுத்து",
    "auth.passwordHint": "குறைந்தது 10 எழுத்துகள், எழுத்துகளும் எண்களும் கலந்து.",
    "auth.haveAccount": "ஏற்கெனவே கணக்கு உள்ளதா? உள்நுழைக",
    "paper.language": "வினாத்தாள் மொழி",
    "paper.both": "இரண்டும்",
    "paper.bothHint": "ஒவ்வொரு வினாவும் இரு மொழிகளிலும்; செயலியின் மொழி முதலில்.",
    "paper.notEnoughBoth": "இரு மொழிகளிலும் போதுமான வினாக்கள் இன்னும் இல்லை. ஒரு மொழியைத் தேர்ந்தெடுக்கவும் அல்லது குறைவான வினாக்களைக் கேட்கவும்.",
    "downloads.languageNote": "ஒவ்வொரு PDF-உம் அந்தத் தேர்வின் மொழியிலேயே அச்சிடப்படும்; இருமொழித் தேர்வுக்கு இரு மொழிகளிலும்.",
    // Practice papers (PDF to timed test) — appended block.
    "nav.papers": "வினாத்தாள் பதிவேற்றம்",
    "papers.title": "வினாத்தாளைப் பதிவேற்று",
    "papers.subtitle": "முந்தைய ஆண்டு வினாத்தாளையோ, (A)–(D) அல்லது அ)–ஈ) விடைகள் உள்ள எந்தக் கொள்குறி வினாத்தாளையோ பதிவேற்றுங்கள். அதை உடனே நேரத் தேர்வாக எழுதலாம்.",
    "papers.file": "வினாத்தாள் (PDF)",
    "papers.paperTitle": "தலைப்பு",
    "papers.minutes": "நேர வரம்பு (நிமிடங்கள்)",
    "papers.minutesHint": "காலியாக விட்டால் ஒரு வினாவுக்கு 1 நிமிடம்",
    "papers.upload": "வினாத்தாளைப் படி",
    "papers.reading": "வினாத்தாள் படிக்கப்படுகிறது…",
    "papers.ocr": "இந்த PDF ஒரு ஸ்கேன் என்பதால் அதன் பக்கங்கள் OCR மூலம் படிக்கப்படுகின்றன. இதற்குச் சில நிமிடங்கள் ஆகலாம்; இந்தப் பக்கத்தை விட்டுச் சென்று பின்னர் உங்கள் பட்டியலில் வினாத்தாளைக் காணலாம்.",
    "papers.report": "படிக்கப்பட்டவை",
    "papers.parsed": "படிக்கப்பட்ட வினாக்கள்",
    "papers.skipped": "விடுபட்டவை",
    "papers.keyFound": "விடைக்குறிப்பு",
    "papers.keyMissing": "கிடைக்கவில்லை",
    "papers.languages": "மொழிகள்",
    "papers.pages": "பக்கங்கள்",
    "papers.warnings": "சரிபார்க்கவும்",
    "papers.start": "நேரத் தேர்வைத் தொடங்கு",
    "papers.mine": "நீங்கள் பதிவேற்றிய வினாத்தாள்கள்",
    "papers.none": "இன்னும் எந்த வினாத்தாளும் பதிவேற்றப்படவில்லை.",
    "papers.retake": "மீண்டும் எழுது",
    "papers.resume": "தொடர்க",
    "papers.attempts": "முயற்சிகள்",
    "papers.best": "சிறந்தது",
    "papers.keyed": "விடையுடன்",
    "papers.addKey": "விடைக்குறிப்பைச் சேர்",
    "papers.keyHint": "ஒவ்வொரு வினாவுக்கும் ஒன்று, எ.கா. 1-A 2-C 3-B (அ ஆ இ ஈ-யும் பயன்படுத்தலாம்)",
    "papers.saveKey": "விடைக்குறிப்பைச் சேமி",
    "papers.keySaved": "புதுப்பிக்கப்பட்ட வினாக்கள்",
    "papers.notRescored": "முந்தைய முயற்சிகள் மறுமதிப்பீடு செய்யப்படாது. இந்த விடைக்குறிப்பின்படி மதிப்பெண் பெற மீண்டும் எழுதவும்.",
    "papers.unmatched": "இந்த வினாத்தாளில் இல்லாதவை",
    "papers.unscored": "விடைக்குறிப்பு இல்லை — மதிப்பிடப்படவில்லை",
    "papers.unscoredCount": "வினாக்களுக்கு விடைக்குறிப்பு இல்லாததால் அவை மதிப்பெண்ணில் சேர்க்கப்படவில்லை.",
    "papers.failed": "வினாத்தாளைப் படிக்க முடியவில்லை.",
    "papers.chooseFile": "முதலில் ஒரு PDF-ஐத் தேர்ந்தெடுக்கவும்.",
    "papers.lang.en": "ஆங்கிலம்",
    "papers.lang.ta": "தமிழ்",
    "paper.unavailable": "மொழிபெயர்ப்பு கிடைக்கவில்லை",
  },
} as const;

export type MessageKey = keyof (typeof STRINGS)["en"];

const STORAGE_KEY = "examforge.locale";

// The chosen locale lives in localStorage, outside React; this is a minimal
// external store over it. memory covers private mode, where storage throws.
let memory: Locale = "en";
const listeners = new Set<() => void>();

function stored(): Locale {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY);
    return value === "ta" ? "ta" : value === "en" ? "en" : memory;
  } catch {
    return memory;
  }
}

function subscribe(callback: () => void): () => void {
  listeners.add(callback);
  window.addEventListener("storage", callback);
  return () => {
    listeners.delete(callback);
    window.removeEventListener("storage", callback);
  };
}

function store(next: Locale): void {
  memory = next;
  try {
    window.localStorage.setItem(STORAGE_KEY, next);
  } catch {
    // Private mode: the choice lasts for this page only.
  }
  listeners.forEach((listener) => listener());
}

interface I18n {
  locale: Locale;
  setLocale: (locale: Locale) => void;
  t: (key: MessageKey) => string;
}

const Context = createContext<I18n>({
  locale: "en",
  setLocale: () => undefined,
  t: (key) => STRINGS.en[key],
});

export function I18nProvider({ children }: { children: ReactNode }) {
  // English on the server render; the stored choice once in the browser.
  const locale = useSyncExternalStore(subscribe, stored, () => "en" as Locale);
  const setLocale = useCallback((next: Locale) => store(next), []);

  const t = useCallback((key: MessageKey) => STRINGS[locale][key] ?? STRINGS.en[key], [locale]);

  return <Context.Provider value={{ locale, setLocale, t }}>{children}</Context.Provider>;
}

export function useI18n(): I18n {
  return useContext(Context);
}
