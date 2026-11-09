/** The 46 launch industries, their families and terminology. Sample figures are invented for the prototype. */
export type FamilyKey = "property" | "home" | "money" | "legal" | "care" | "vehicles" | "business" | "life";
export type Tier = "A" | "B" | "C";

export const families: Record<FamilyKey, { name: string; accent: string; soft: string }> = {
  property: { name: "Real estate and property", accent: "#2451F5", soft: "rgba(36,81,245,.12)" },
  home: { name: "Home services", accent: "#FF7A1A", soft: "rgba(255,122,26,.14)" },
  money: { name: "Insurance and financial", accent: "#1E3A8A", soft: "rgba(30,58,138,.12)" },
  legal: { name: "Legal", accent: "#475569", soft: "rgba(71,85,105,.14)" },
  care: { name: "Health and care", accent: "#0F766E", soft: "rgba(15,118,110,.12)" },
  vehicles: { name: "Vehicles and equipment", accent: "#B45309", soft: "rgba(180,83,9,.13)" },
  business: { name: "Business services", accent: "#6D28D9", soft: "rgba(109,40,217,.12)" },
  life: { name: "Events, travel and lifestyle", accent: "#BE185D", soft: "rgba(190,24,93,.11)" },
};

export type Industry = { slug: string; name: string; group: string; family: FamilyKey; tier: Tier; territory: string; icon: string; pro: string; pros: string; client: string; claimed: number };

const I = (slug: string, name: string, group: string, family: FamilyKey, tier: Tier, territory: string, icon: string, pro: string, client: string, claimed: number): Industry =>
  ({ slug, name, group, family, tier, territory, icon, pro, pros: pro.endsWith("s") ? pro : pro + "s", client, claimed });

export const industries: Industry[] = [
  I("real-estate", "Real estate", "Residential Agent Alliance", "property", "A", "City", "Home", "agent", "buyer or seller", 1240),
  I("commercial-real-estate", "Commercial real estate", "Commercial Property Brokers Network", "property", "A", "Metro", "Building2", "broker", "client", 312),
  I("property-management", "Property management", "Property Managers Network", "property", "A", "City", "KeyRound", "property manager", "owner", 486),
  I("mortgage", "Mortgage lending", "Mortgage Lenders Circle", "money", "B", "City", "Landmark", "loan officer", "borrower", 701),
  I("title-escrow", "Title and escrow", "Title & Escrow Network", "property", "B", "County", "FileSignature", "title officer", "client", 388),
  I("home-inspection", "Home inspection", "Home Inspectors Network", "home", "B", "City", "SearchCheck", "inspector", "homeowner", 455),
  I("moving", "Moving companies", "Movers Network", "home", "A", "City", "Truck", "mover", "customer", 529),
  I("insurance", "Insurance", "Insurance Agents Alliance", "money", "B", "City", "Shield", "agent", "client", 812),
  I("medicare", "Medicare insurance", "Medicare Agents Network", "money", "B", "County", "ShieldPlus", "Medicare agent", "beneficiary", 334),
  I("solar", "Solar", "Solar Installers Network", "home", "A", "City", "Sun", "installer", "homeowner", 618),
  I("roofing", "Roofing", "Roofing Contractors Network", "home", "A", "City", "Construction", "roofer", "homeowner", 574),
  I("hvac", "HVAC", "HVAC Pros Network", "home", "A", "City", "AirVent", "HVAC pro", "homeowner", 603),
  I("windows-siding", "Windows and siding", "Windows & Siding Pros Network", "home", "A", "City", "AppWindow", "contractor", "homeowner", 297),
  I("pest-control", "Pest control", "Pest Control Pros Network", "home", "A", "City", "Bug", "technician", "customer", 341),
  I("home-security", "Home security", "Home Security Pros Network", "home", "A", "City", "Siren", "security pro", "customer", 266),
  I("pools", "Pool builders", "Pool Builders Network", "home", "A", "City", "Waves", "pool builder", "homeowner", 188),
  I("water-treatment", "Water treatment", "Water Treatment Pros Network", "home", "A", "City", "Droplets", "water specialist", "homeowner", 172),
  I("home-warranty", "Home warranty", "Home Warranty Network", "home", "B", "State", "BadgeCheck", "warranty rep", "homeowner", 41),
  I("financial-advisors", "Financial advisors", "Financial Advisors Alliance", "money", "B", "City", "LineChart", "advisor", "client", 590),
  I("tax-accounting", "Tax and accounting", "Tax & Accounting Pros Network", "money", "B", "City", "Calculator", "accountant", "client", 433),
  I("estate-planning", "Estate planning attorneys", "Estate Planning Attorneys Directory", "legal", "C", "County", "Scale", "attorney", "client", 214),
  I("personal-injury", "Personal injury attorneys", "Personal Injury Attorneys Directory", "legal", "C", "County", "Gavel", "attorney", "client", 236),
  I("business-brokers", "Business brokers", "Business Brokers Network", "business", "A", "Metro", "Briefcase", "business broker", "owner", 158),
  I("auto-sales", "Auto sales", "Auto Dealers Network", "vehicles", "A", "Metro", "Car", "dealer", "buyer", 402),
  I("rv-boat", "RV and boat sales", "RV & Boat Dealers Network", "vehicles", "A", "Metro", "Sailboat", "dealer", "buyer", 133),
  I("senior-living", "Senior living placement", "Senior Living Advisors Network", "care", "B", "City", "HeartHandshake", "placement advisor", "family", 289),
  I("in-home-care", "In-home care", "Home Care Providers Network", "care", "C", "City", "HandHeart", "care provider", "family", 247),
  I("hospice", "Hospice", "Hospice Providers Network", "care", "C", "County", "HeartPulse", "hospice provider", "family", 119),
  I("staffing", "Staffing and recruiting", "Staffing & Recruiting Network", "business", "A", "Metro", "Users", "recruiter", "employer", 221),
  I("travel", "Travel agents", "Travel Advisors Network", "life", "A", "State", "Plane", "travel advisor", "traveller", 38),
  I("weddings-events", "Wedding and event vendors", "Wedding & Event Pros Network", "life", "A", "City", "PartyPopper", "vendor", "couple", 364),
  I("elective-medical", "Elective medical", "Elective Care Providers Network", "care", "B", "City", "Sparkles", "practice", "patient", 205),
  I("fitness", "Fitness and gyms", "Fitness Clubs Network", "life", "A", "City", "Dumbbell", "club", "member", 312),
  I("franchise-sales", "Franchise sales", "Franchise Consultants Network", "business", "A", "State", "Store", "consultant", "candidate", 33),
  I("telecom", "Telecom and internet", "Telecom & Internet Partners Network", "business", "A", "State", "Wifi", "partner", "customer", 29),
  I("business-software", "Business software", "B2B Software Partners Network", "business", "A", "State", "Laptop", "partner", "company", 36),
  I("merchant-services", "Merchant services", "Merchant Services Partners Network", "business", "A", "State", "CreditCard", "partner", "merchant", 40),
  I("commercial-cleaning", "Commercial cleaning", "Commercial Cleaning Network", "business", "A", "City", "SprayCan", "cleaning company", "facility", 251),
  I("landscaping", "Landscaping and hardscaping", "Landscape & Hardscape Pros Network", "home", "A", "City", "Trees", "landscaper", "homeowner", 398),
  I("custom-builders", "Custom home builders", "Custom Home Builders Network", "property", "A", "County", "Hammer", "builder", "client", 176),
  I("land", "Land sales", "Land Brokers Network", "property", "A", "County", "Map", "land broker", "buyer or seller", 143),
  I("ranch-farm", "Ranch and farm sales", "Ranch & Farm Brokers Network", "property", "A", "County", "Tractor", "ranch broker", "buyer or seller", 97),
  I("livestock", "Livestock sales", "Livestock Marketing Network", "vehicles", "A", "State", "Beef", "livestock marketer", "producer", 22),
  I("equipment", "Equipment sales", "Equipment Dealers Network", "vehicles", "A", "State", "Forklift", "dealer", "buyer", 31),
  I("funeral-preplanning", "Funeral pre-planning", "Pre-Need Planning Network", "life", "B", "County", "Flower2", "pre-need counselor", "family", 104),
  I("timeshare", "Timeshare and vacation", "Vacation Ownership Network", "life", "B", "State", "Palmtree", "specialist", "owner", 27),
];

export const bySlug = (slug: string) => industries.find(i => i.slug === slug) ?? industries[0]!;
export const totalClaimed = industries.reduce((s, i) => s + i.claimed, 0);

export const tierCopy: Record<Tier, { label: string; consumer: boolean; note: string }> = {
  A: { label: "Open", consumer: true, note: "Members can agree referral fees with each other, paid business to business." },
  B: { label: "Restricted", consumer: true, note: "Referrals are exchanged without fees between members, in line with industry rules. Members earn reciprocity credits instead." },
  C: { label: "Directory", consumer: false, note: "A verified directory listing. No paid referrals, in line with professional rules for this field." },
};

/** Metros for the national map. Status per industry is derived deterministically from the name. */
export const metros: { city: string; st: string; lat: number; lon: number }[] = [
  ["New York", "NY", 40.71, -74.01], ["Los Angeles", "CA", 34.05, -118.24], ["Chicago", "IL", 41.88, -87.63], ["Houston", "TX", 29.76, -95.37], ["Phoenix", "AZ", 33.45, -112.07],
  ["Philadelphia", "PA", 39.95, -75.17], ["San Antonio", "TX", 29.42, -98.49], ["San Diego", "CA", 32.72, -117.16], ["Dallas", "TX", 32.78, -96.8], ["Austin", "TX", 30.27, -97.74],
  ["Jacksonville", "FL", 30.33, -81.66], ["Columbus", "OH", 39.96, -83.0], ["Charlotte", "NC", 35.23, -80.84], ["Indianapolis", "IN", 39.77, -86.16], ["San Francisco", "CA", 37.77, -122.42],
  ["Seattle", "WA", 47.61, -122.33], ["Denver", "CO", 39.74, -104.99], ["Nashville", "TN", 36.16, -86.78], ["Oklahoma City", "OK", 35.47, -97.52], ["El Paso", "TX", 31.76, -106.49],
  ["Boston", "MA", 42.36, -71.06], ["Portland", "OR", 45.52, -122.68], ["Las Vegas", "NV", 36.17, -115.14], ["Detroit", "MI", 42.33, -83.05], ["Memphis", "TN", 35.15, -90.05],
  ["Louisville", "KY", 38.25, -85.76], ["Baltimore", "MD", 39.29, -76.61], ["Milwaukee", "WI", 43.04, -87.91], ["Albuquerque", "NM", 35.08, -106.65], ["Tucson", "AZ", 32.22, -110.97],
  ["Fresno", "CA", 36.74, -119.79], ["Sacramento", "CA", 38.58, -121.49], ["Kansas City", "MO", 39.1, -94.58], ["Atlanta", "GA", 33.75, -84.39], ["Omaha", "NE", 41.26, -95.93],
  ["Raleigh", "NC", 35.78, -78.64], ["Miami", "FL", 25.76, -80.19], ["Minneapolis", "MN", 44.98, -93.27], ["Tulsa", "OK", 36.15, -95.99], ["Tampa", "FL", 27.95, -82.46],
  ["New Orleans", "LA", 29.95, -90.07], ["Cleveland", "OH", 41.5, -81.69], ["Orlando", "FL", 28.54, -81.38], ["St. Louis", "MO", 38.63, -90.2], ["Pittsburgh", "PA", 40.44, -80.0],
  ["Cincinnati", "OH", 39.1, -84.51], ["Salt Lake City", "UT", 40.76, -111.89], ["St. George", "UT", 37.1, -113.58], ["Boise", "ID", 43.62, -116.2], ["Spokane", "WA", 47.66, -117.43],
  ["Reno", "NV", 39.53, -119.81], ["Billings", "MT", 45.78, -108.5], ["Cheyenne", "WY", 41.14, -104.82], ["Sioux Falls", "SD", 43.55, -96.73], ["Fargo", "ND", 46.88, -96.79],
  ["Des Moines", "IA", 41.59, -93.62], ["Little Rock", "AR", 34.75, -92.29], ["Jackson", "MS", 32.3, -90.18], ["Birmingham", "AL", 33.52, -86.8], ["Charleston", "SC", 32.78, -79.93],
  ["Savannah", "GA", 32.08, -81.09], ["Richmond", "VA", 37.54, -77.44], ["Virginia Beach", "VA", 36.85, -75.98], ["Washington", "DC", 38.91, -77.04], ["Hartford", "CT", 41.76, -72.69],
  ["Providence", "RI", 41.82, -71.41], ["Portland", "ME", 43.66, -70.26], ["Burlington", "VT", 44.48, -73.21], ["Manchester", "NH", 42.99, -71.46], ["Buffalo", "NY", 42.89, -78.88],
  ["Albany", "NY", 42.65, -73.76], ["Newark", "NJ", 40.74, -74.17], ["Wilmington", "DE", 39.74, -75.55], ["Charleston", "WV", 38.35, -81.63], ["Knoxville", "TN", 35.96, -83.92],
  ["Lexington", "KY", 38.04, -84.5], ["Grand Rapids", "MI", 42.96, -85.67], ["Madison", "WI", 43.07, -89.4], ["Wichita", "KS", 37.69, -97.34], ["Lubbock", "TX", 33.58, -101.86],
  ["Corpus Christi", "TX", 27.8, -97.4], ["Shreveport", "LA", 32.53, -93.75], ["Pensacola", "FL", 30.42, -87.22], ["Fort Myers", "FL", 26.64, -81.87], ["Colorado Springs", "CO", 38.83, -104.82],
  ["Santa Fe", "NM", 35.69, -105.94], ["Flagstaff", "AZ", 35.2, -111.65], ["Bakersfield", "CA", 35.37, -119.02], ["Eugene", "OR", 44.05, -123.09], ["Anchorage", "AK", 61.22, -149.9], ["Honolulu", "HI", 21.31, -157.86],
].map(([city, st, lat, lon]) => ({ city: city as string, st: st as string, lat: lat as number, lon: lon as number }));

export type SeatStatus = "claimed" | "reserved" | "open";
/** Deterministic pseudo-random status so the map looks the same on every load. */
export function seatStatus(city: string, industrySlug: string, claimedShare = 0.62): SeatStatus {
  let h = 2166136261;
  for (const ch of city + industrySlug) h = Math.imul(h ^ ch.charCodeAt(0), 16777619);
  const r = ((h >>> 0) % 1000) / 1000;
  return r < claimedShare ? "claimed" : r < claimedShare + 0.18 ? "reserved" : "open";
}
