/** Fictional sample data used by the prototype. Names, teams and figures are invented. */
export type Group = { slug: string; name: string; owner: string; ownerCity: string; tagline: string; teams: number; filled: number; total: number;
  referrals: number; fees: number; open: string[]; hue: "cobalt" | "orange" | "navy"; access: "Open" | "Invite only" };

export const groups: Group[] = [
  { slug: "sun-belt-top-teams", name: "Sun Belt Top Teams", owner: "Maria Alvarez", ownerCity: "Phoenix, AZ", tagline: "Top-producing teams across the southern US, vetted by production and response time.", teams: 214, filled: 214, total: 260, referrals: 3842, fees: 4120000, open: ["Mobile, AL", "Lubbock, TX", "Shreveport, LA", "Pensacola, FL"], hue: "orange", access: "Open" },
  { slug: "mountain-west-alliance", name: "Mountain West Alliance", owner: "Jordan Hale", ownerCity: "St. George, UT", tagline: "Utah, Idaho, Colorado, Nevada and Montana teams who answer within the hour.", teams: 96, filled: 96, total: 140, referrals: 1310, fees: 1460000, open: ["Twin Falls, ID", "Pueblo, CO", "Elko, NV"], hue: "cobalt", access: "Open" },
  { slug: "coastal-luxury-collective", name: "Coastal Luxury Collective", owner: "Priya Raman", ownerCity: "Miami, FL", tagline: "Luxury specialists in coastal markets, $1M+ transactions only.", teams: 58, filled: 58, total: 70, referrals: 412, fees: 2980000, open: ["Naples, FL", "Charleston, SC"], hue: "navy", access: "Invite only" },
  { slug: "relocation-pros-network", name: "Relocation Pros Network", owner: "Derek Owens", ownerCity: "Dallas, TX", tagline: "Teams that specialise in corporate and military relocation.", teams: 131, filled: 131, total: 220, referrals: 2275, fees: 2210000, open: ["Fayetteville, NC", "Killeen, TX", "Colorado Springs, CO", "Tacoma, WA"], hue: "orange", access: "Open" },
  { slug: "first-home-specialists", name: "First Home Specialists", owner: "Lena Brooks", ownerCity: "Columbus, OH", tagline: "First-time buyer experts with down-payment programme know-how.", teams: 77, filled: 77, total: 150, referrals: 958, fees: 690000, open: ["Toledo, OH", "Fort Wayne, IN", "Peoria, IL"], hue: "cobalt", access: "Open" },
  { slug: "great-lakes-referral-circle", name: "Great Lakes Referral Circle", owner: "Tom Kowalski", ownerCity: "Chicago, IL", tagline: "Midwest teams with a shared promise: every update, every week.", teams: 64, filled: 64, total: 110, referrals: 701, fees: 640000, open: ["Green Bay, WI", "Erie, PA"], hue: "navy", access: "Open" },
];

export type SeatRow = { state: string; city: string; team: string; leader: string; trust: number | "new"; sent: number; received: number; conversion: number; response: string };
export const sunBeltSeats: SeatRow[] = [
  { state: "Texas", city: "Houston", team: "Bayou City Homes", leader: "Chris Delgado", trust: 94, sent: 41, received: 63, conversion: 38, response: "11 min" },
  { state: "Texas", city: "Austin", team: "Capitol Key Realty", leader: "Hannah Price", trust: 91, sent: 52, received: 47, conversion: 34, response: "18 min" },
  { state: "Texas", city: "San Antonio", team: "Alamo Front Door", leader: "Luis Ortega", trust: 88, sent: 29, received: 35, conversion: 31, response: "26 min" },
  { state: "Arizona", city: "Phoenix", team: "Desert Peak Group", leader: "Maria Alvarez", trust: 97, sent: 66, received: 58, conversion: 41, response: "9 min" },
  { state: "Arizona", city: "Tucson", team: "Old Pueblo Partners", leader: "Sam Whitaker", trust: 85, sent: 18, received: 22, conversion: 27, response: "34 min" },
  { state: "Florida", city: "Tampa", team: "Bay Area Living", leader: "Keisha Grant", trust: 90, sent: 37, received: 44, conversion: 33, response: "15 min" },
  { state: "Florida", city: "Orlando", team: "Central Keys Team", leader: "Ben Ashford", trust: "new", sent: 2, received: 1, conversion: 0, response: "—" },
  { state: "Georgia", city: "Atlanta", team: "Peachtree Partners", leader: "Monique Hayes", trust: 92, sent: 48, received: 51, conversion: 36, response: "14 min" },
];

export const houston = {
  city: "Houston", state: "Texas", stateCode: "TX", industry: "Real estate",
  seats: [
    { group: "Sun Belt Top Teams", team: "Bayou City Homes", leader: "Chris Delgado", trust: 94, response: "11 min", closed: 24 },
    { group: "Relocation Pros Network", team: "Gulf Coast Moves", leader: "Aisha Patel", trust: 89, response: "22 min", closed: 17 },
    { group: "Great Lakes Referral Circle", team: "", leader: "", trust: 0, response: "", closed: 0 },
  ],
  partners: [
    { name: "Lone Star Home Loans", kind: "Mortgage lender" },
    { name: "Harbor Title Co.", kind: "Title company" },
    { name: "Clearview Inspections", kind: "Home inspector" },
  ],
};

export const network = { teams: 640, groups: 6, cities: 640, states: 31, referrals: 9498, fees: 12100000, avgAccept: "16 min" };
